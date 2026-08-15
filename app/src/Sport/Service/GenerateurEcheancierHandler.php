<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Enum\StatutEcheanceSepa;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère l'échéancier SEPA (dates + montants) jusqu'à la fin d'engagement (CA-1). Réutilisé par la
 * souscription et le réengagement (T4/T8 du plan).
 */
final class GenerateurEcheancierHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function generer(AbonnementFitness $abonnement, int $montantCentimes): void
    {
        $increment = $abonnement->getPeriodicite() === PeriodiciteAbonnementFitness::Mensuel ? '+1 month' : '+1 week';
        $date = $abonnement->getDateDebutEngagement();
        $fin = $abonnement->getDateFinEngagement();

        while ($date < $fin) {
            $echeance = new EcheanceSepa();
            $echeance->setAbonnement($abonnement)
                ->setDateProgrammee($date)
                ->setMontantCentimes($montantCentimes)
                ->setStatut(StatutEcheanceSepa::AVenir);
            $this->em->persist($echeance);

            $date = $date->modify($increment);
        }
    }
}
