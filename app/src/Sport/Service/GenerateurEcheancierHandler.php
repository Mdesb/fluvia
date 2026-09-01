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

    /**
     * @param int|null $montantPremiereCentimes Montant de la PREMIÈRE échéance quand il diffère des
     *                                          suivantes — le demi-mois d'une souscription en cours
     *                                          de période. `null` : toutes au même montant.
     *
     * ⚠ LE PRORATA EST UN MONTANT, PAS UN CALCUL. Ce générateur ne le calcule PAS et ne doit pas :
     *    au prorata de quoi, arrondi comment, à partir de quelle date — ce sont des décisions
     *    commerciales qui n'ont pas leur place ici, et une règle inventée serait appliquée en
     *    silence à toutes les souscriptions. L'appelant le fournit ; cette classe le pose.
     */
    public function generer(AbonnementFitness $abonnement, int $montantCentimes, ?int $montantPremiereCentimes = null): void
    {
        $increment = $abonnement->getPeriodicite() === PeriodiciteAbonnementFitness::Mensuel ? '+1 month' : '+1 week';
        $date = $abonnement->getDateDebutEngagement();
        $fin = $abonnement->getDateFinEngagement();
        $premiere = true;

        while ($date < $fin) {
            $echeance = new EcheanceSepa();
            $echeance->setAbonnement($abonnement)
                ->setDateProgrammee($date)
                // ⚠ `?? $montantCentimes` et non `?: ` : un prorata de 0 est une valeur légitime
                //    (le premier mois offert), et `?:` la remplacerait par le montant plein.
                ->setMontantCentimes($premiere && $montantPremiereCentimes !== null ? $montantPremiereCentimes : $montantCentimes)
                ->setStatut(StatutEcheanceSepa::AVenir);
            $this->em->persist($echeance);

            $premiere = false;
            $date = $date->modify($increment);
        }
    }
}
