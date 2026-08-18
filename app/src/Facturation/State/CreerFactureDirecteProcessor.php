<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\OrigineFacture;
use App\Facturation\Service\FactureDirecteBuilder;
use App\Facturation\Service\ResolveurComptesFacturation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /factures (US-FACT-02, `plan-facturation.md` §2) : crée un **brouillon** de facture directe
 * (aucun numéro consommé, RG-FACT-01), composé de son destinataire et de ses lignes. Corps :
 *   { "destinataire": {...}, "lignes": [...], "dateEcheance"?: "YYYY-MM-DD", "conditionsReglement"?: "…" }
 *
 * @implements ProcessorInterface<mixed, Facture>
 */
final class CreerFactureDirecteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly FactureDirecteBuilder $builder,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Facture
    {
        $corps = $this->lecteur->corps();

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }
        $profil = $this->comptes->profilPour($etablissement);

        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $facture = new Facture();
        $facture->setNature(NatureFacture::Facture);
        $facture->setOrigine(OrigineFacture::VenteATerme);
        $facture->setEtablissement($etablissement);
        $facture->setProfilExploitant($profil);
        $facture->setCreePar($utilisateur);

        $this->builder->appliquerDestinataire($facture, \is_array($corps['destinataire'] ?? null) ? $corps['destinataire'] : []);
        $this->builder->appliquerLignes($facture, $corps);

        if (\is_string($corps['dateEcheance'] ?? null)) {
            $facture->setDateEcheance(new \DateTimeImmutable($corps['dateEcheance']));
        }
        if (\is_string($corps['conditionsReglement'] ?? null)) {
            $facture->setConditionsReglement($corps['conditionsReglement']);
        } else {
            $facture->setConditionsReglement($this->comptes->parametre($profil)?->conditionsCompletes());
        }

        $this->em->persist($facture);
        $this->em->flush();

        return $facture;
    }
}
