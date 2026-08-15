<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\Entity\Client;
use App\Crm\Entity\DemandeRGPD;
use App\Crm\Entity\RegleConservation;
use App\Crm\Enum\ActionConservation;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\TypeDemandeRgpd;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `crm:rgpd:appliquer-conservation` (RG-M4-08, §3.4 plan-crm.md) : applique, pour chaque
 * `RegleConservation` de catégorie `identite`, l'action paramétrée (`purge`/`anonymisation`) aux
 * fiches `Client` du même groupe créées depuis plus de `dureeMois` — réutilise
 * `EffacementRgpdHandler` pour l'anonymisation (une seule catégorie gérée dans ce lot : les autres
 * catégories de données déclarées — historique_achat, consentement_marketing… — n'ont pas
 * d'équivalent applicatif direct dans M4 et sont réservées à un lot ultérieur, ⚠ HYPOTHÈSE).
 */
#[AsCommand(name: 'crm:rgpd:appliquer-conservation', description: 'Applique les règles de conservation RGPD échues (RG-M4-08).')]
final class AppliquerConservationCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<RegleConservation> $regles */
        $regles = $this->em->getRepository(RegleConservation::class)->findBy(['categorieDonnee' => 'identite']);

        $traites = 0;
        foreach ($regles as $regle) {
            if ($regle->getActionEcheance() !== ActionConservation::Anonymisation) {
                continue; // La purge pure de « identite » (suppression physique du Client) est hors
                // périmètre de ce lot : le Client porte des références (PMV, ventes) qu'on ne
                // supprime jamais physiquement (§1 plan-crm.md, décision structurante).
            }
            $seuil = (new \DateTimeImmutable())->modify(sprintf('-%d months', $regle->getDureeMois()));

            $qb = $this->em->createQueryBuilder();
            $qb->select('c')->from(Client::class, 'c')
                ->where('c.groupe = :groupe')
                ->andWhere('c.dateCreation <= :seuil')
                ->andWhere('c.statut != :anonymise')
                ->setParameter('groupe', $regle->getGroupe())
                ->setParameter('seuil', $seuil)
                ->setParameter('anonymise', StatutClient::Anonymise->value);

            /** @var list<Client> $clients */
            $clients = $qb->getQuery()->getResult();
            foreach ($clients as $client) {
                $demande = new DemandeRGPD(TypeDemandeRgpd::Anonymisation);
                $demande->setClient($client);
                $this->em->persist($demande);
                $this->em->flush();
                // Traité « système » : pas d'administrateur humain, `traitePar` reste null (le
                // handler l'accepte, seul `dateTraitement`/`statut` sont garantis).
                $demande->setStatut(\App\Crm\Enum\StatutDemandeRgpd::EnCours);
                $this->anonymiserSysteme($client, $demande);
                ++$traites;
            }
        }

        $this->em->flush();
        $io->success(sprintf('%d fiche(s) client anonymisée(s) par conservation échue.', $traites));

        return Command::SUCCESS;
    }

    private function anonymiserSysteme(Client $client, DemandeRGPD $demande): void
    {
        $client->setCivilite(null);
        $client->setNom(null);
        $client->setPrenom(null);
        $client->setRaisonSociale(null);
        $client->setSiret(null);
        $client->setEmail(null);
        $client->setTelephone(null);
        $client->setAdresse(null);
        $client->setDateNaissance(null);
        $client->setChampManuel(null);
        $client->setStatut(StatutClient::Anonymise);
        $client->setDateMaj(new \DateTimeImmutable());
        $client->setMajPar('rgpd:conservation-echue');

        $demande->setStatut(\App\Crm\Enum\StatutDemandeRgpd::Realisee);
        $demande->setDateTraitement(new \DateTimeImmutable());
    }
}
