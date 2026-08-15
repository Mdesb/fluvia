<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Offre\Entity\Formule;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\StatutAccesFitness;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Enum\StatutAbonnementFitness;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Souscription d'un abonnement fitness (US-SPORT-01, CA-1). Crée l'abonnement, tokenise l'IBAN
 * (jamais persisté en clair) et signe le mandat, génère l'échéancier jusqu'à la fin d'engagement, et
 * ouvre un `StatutAccesFitness` (droit d'accès rattaché ultérieurement, §0 point 5 du plan).
 */
final class SouscriptionAbonnementHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TokenisationIbanInterface $tokenisation,
        private readonly GenerateurEcheancierHandler $echeancier,
    ) {
    }

    public function souscrire(
        Beneficiaire $adherent,
        Client $payeur,
        Formule $formule,
        Etablissement $etablissement,
        PeriodiciteAbonnementFitness $periodicite,
        \DateTimeImmutable $dateSouscription,
        int $dureeEngagementMois,
        int $montantCentimes,
        string $ibanClair,
        string $titulaireMandat,
    ): AbonnementFitness {
        $abonnement = new AbonnementFitness();
        $abonnement->setAdherent($adherent)
            ->setPayeur($payeur)
            ->setFormule($formule)
            ->setEtablissement($etablissement)
            ->setPeriodicite($periodicite)
            ->setStatut(StatutAbonnementFitness::Actif)
            ->setDateSouscription($dateSouscription)
            ->setDateDebutEngagement($dateSouscription)
            ->setDateFinEngagement($dateSouscription->modify(sprintf('+%d months', $dureeEngagementMois)));

        $engagement = $formule->getEngagement() ?? [];
        $preavis = (int) ($engagement['resiliation'] ?? 30);
        $abonnement->setPreavisResiliationJours($preavis);

        // `MandatSepa` (module partagé `App\Sepa`) est générique : rattaché au client + établissement
        // directement, plus de cycle 1:1 à casser côté mandat (contrairement à l'ancien
        // `MandatSepaFitness.abonnementRattache`) — le mandat est inséré une seule fois.
        $token = $this->tokenisation->tokeniser($ibanClair);
        $mandat = new MandatSepa();
        $mandat->setRum($this->genererRum($abonnement))
            ->setIbanToken($token->token)
            ->setIban4Derniers($token->quatreDerniers)
            ->setDebiteurNom($titulaireMandat)
            ->setDateSignature($dateSouscription)
            ->setStatut(StatutMandatSepa::Actif)
            ->setClient($payeur)
            ->setEtablissement($etablissement);
        $this->em->persist($mandat);
        $this->em->flush();

        $abonnement->setMandatSepa($mandat);
        $this->em->persist($abonnement);

        $this->echeancier->generer($abonnement, $montantCentimes);

        $statutAcces = new StatutAccesFitness();
        $statutAcces->setAbonnement($abonnement)->setActif(true);
        $this->em->persist($statutAcces);

        $this->em->flush();

        return $abonnement;
    }

    private function genererRum(AbonnementFitness $abonnement): string
    {
        return 'RUM-' . strtoupper(substr(hash('sha256', (string) $abonnement->getId() . microtime()), 0, 20));
    }
}
