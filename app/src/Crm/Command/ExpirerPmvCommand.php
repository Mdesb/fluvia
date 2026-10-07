<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\Entity\MouvementPmv;
use App\Crm\Entity\ParametrePmvEtablissement;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Enum\StatutPmv;
use App\Crm\Enum\TraitementSoldeResiduel;
use App\Crm\Enum\TypeMouvementPmv;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `crm:rgpd:expirer-pmv` (US-L5-07, CA-12) : traite les PMV actifs dont l'échéance est dépassée selon
 * le traitement paramétré par établissement (conservé/annulé/transformé en produit) — génère un
 * `MouvementPmv(expiration)` daté/motivé/exportable ; un solde annulé reste visible dans l'historique
 * (jamais supprimé).
 */
#[AsCommand(name: 'crm:rgpd:expirer-pmv', description: 'Traite les PMV actifs dont la date d\'échéance est dépassée (US-L5-07).')]
final class ExpirerPmvCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maintenant = new \DateTimeImmutable();

        $qb = $this->em->createQueryBuilder();
        $qb->select('p')->from(PorteMonnaieVirtuel::class, 'p')
            ->where('p.statut = :actif')
            ->andWhere('p.dateEcheance < :maintenant')
            ->setParameter('actif', StatutPmv::Actif->value)
            ->setParameter('maintenant', $maintenant, 'date_immutable');

        /** @var list<PorteMonnaieVirtuel> $pmvExpires */
        $pmvExpires = $qb->getQuery()->getResult();

        $traites = 0;
        foreach ($pmvExpires as $pmv) {
            $etablissement = $pmv->getClient()?->getEtablissementCreation();
            if ($etablissement === null) {
                // Jamais « le premier établissement venu » (04/10/2026) : on signale et on passe, sans
                // arrêter l'expiration des autres porte-monnaie.
                $io->warning(sprintf('PMV %s ignoré : son client n’a pas d’établissement.', $pmv->getId()));
                continue;
            }
            $parametre = $etablissement === null
                ? null
                : $this->em->getRepository(ParametrePmvEtablissement::class)->findOneBy(['etablissement' => $etablissement]);
            $traitement = $parametre?->getTraitementSoldeResiduel() ?? TraitementSoldeResiduel::Conserve;

            $soldeAvant = $pmv->getSolde();
            $mouvement = new MouvementPmv(TypeMouvementPmv::Expiration);
            $mouvement->setPmv($pmv);
            $mouvement->setEtablissement($etablissement);

            switch ($traitement) {
                case TraitementSoldeResiduel::Annule:
                    $mouvement->setMontant('-' . $soldeAvant);
                    $pmv->setSolde('0.00');
                    $mouvement->setMotif('Expiration PMV : solde annulé (paramètre établissement, US-L5-07).');
                    break;
                case TraitementSoldeResiduel::TransformeEnProduit:
                    $mouvement->setMontant('-' . $soldeAvant);
                    $pmv->setSolde('0.00');
                    $mouvement->setMotif('Expiration PMV : solde transformé en produit (exposé pour rapprochement M6, US-L5-07).');
                    break;
                case TraitementSoldeResiduel::Conserve:
                default:
                    $mouvement->setMontant('0.00');
                    $mouvement->setMotif('Expiration PMV : solde conservé (paramètre établissement, US-L5-07).');
                    break;
            }
            $mouvement->setSoldeApres($pmv->getSolde());
            $pmv->setStatut(StatutPmv::Expire);

            $this->em->persist($mouvement);
            ++$traites;
        }

        $this->em->flush();
        $io->success(sprintf('%d PMV expiré(s) traité(s).', $traites));

        return Command::SUCCESS;
    }
}
