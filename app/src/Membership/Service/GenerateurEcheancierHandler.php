<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Membership\Entity\Membership;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Enum\StatutEcheanceSepa;
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
    public function generer(Membership $abonnement, int $montantCentimes, ?int $montantPremiereCentimes = null): void
    {
        // ⚠ CE TERNAIRE AURAIT RENDU UN ABONNEMENT ANNUEL HEBDOMADAIRE, EN SILENCE. Il s'écrivait
        //    `=== Mensuel ? '+1 month' : '+1 week'` : tout ce qui n'est pas mensuel devenait
        //    hebdomadaire, et ajouter un cas à l'énumération ne l'aurait pas fait broncher. Le pas
        //    vit désormais sur l'énumération, dans un `match` exhaustif.
        $periodicite = $abonnement->getPeriodicite();
        $increment = $periodicite->increment();
        $date = $abonnement->getDateDebutEngagement();
        $fin = $abonnement->getDateFinEngagement();
        $premiere = true;

        // ⚠ `jourPrelevement` ÉTAIT RÉGLABLE ET LU PAR PERSONNE. Le champ est mappé et exposé en
        //    `produit:write` et `formule:write` — un exploitant pouvait donc régler « prélèvement le
        //    5 » depuis l'écran produit, et tout le monde restait prélevé à sa date anniversaire.
        //    Mesuré le 06/09 : zéro lecture de `getJourPrelevement()` hors de l'entité.
        //
        // ⚠ PAS L'HEBDOMADAIRE. Une cadence hebdomadaire n'a pas de « jour du mois » ; appliquer la
        //    règle y produirait des échéances aux intervalles arbitraires. Le mensuel ET l'annuel en
        //    ont un : le 5 du mois, ou le 5 du mois anniversaire.
        $jour = $periodicite->porteUnJourDuMois() ? $abonnement->getFormule()?->getJourPrelevement() : null;
        if ($jour !== null && ($jour < 1 || $jour > 31)) {
            // Une valeur hors bornes ne se corrige pas en silence : on garde le comportement connu.
            $jour = null;
        }

        $precedente = null;

        while ($date < $fin) {
            // ⚠ LA PREMIÈRE NE BOUGE PAS, ET C'EST LE POINT DÉLICAT. C'est elle que l'appelant
            //    dimensionne avec `montantPremiereCentimes` — le prorata d'une souscription en cours
            //    de période. La déplacer changerait en silence ce que ce montant couvre, alors que
            //    le prorata est une décision commerciale qui n'appartient pas à cette classe.
            $posee = $premiere || $jour === null ? $date : $this->surLeJour($date, $jour);

            // Le garde n'existe que contre l'inversion. Un écart court reste possible et légitime :
            // souscription le 31 avec un jour 1, c'est une échéance de prorata d'un jour, et c'est
            // à l'appelant de l'avoir dimensionnée.
            if ($precedente !== null && $posee <= $precedente) {
                $posee = $this->surLeJour($posee->modify('+1 month'), $jour ?? (int) $posee->format('j'));
            }

            $echeance = new EcheanceSepa();
            $echeance->setAbonnement($abonnement)
                ->setDateProgrammee($posee)
                // ⚠ `?? $montantCentimes` et non `?: ` : un prorata de 0 est une valeur légitime
                //    (le premier mois offert), et `?:` la remplacerait par le montant plein.
                ->setMontantCentimes($premiere && $montantPremiereCentimes !== null ? $montantPremiereCentimes : $montantCentimes)
                ->setStatut(StatutEcheanceSepa::AVenir);
            $this->em->persist($echeance);

            $premiere = false;
            $precedente = $posee;
            $date = $date->modify($increment);
        }
    }

    /**
     * La même date, posée sur le jour demandé du mois — borné à la longueur de ce mois.
     *
     * ⚠ SANS LA BORNE, `setDate(2026, 2, 31)` NE LÈVE RIEN : il déborde sur le 3 mars. Une échéance
     * silencieusement décalée d'un mois est exactement le genre d'erreur qu'on découvre au relevé.
     */
    private function surLeJour(\DateTimeImmutable $date, int $jour): \DateTimeImmutable
    {
        return $date->setDate(
            (int) $date->format('Y'),
            (int) $date->format('n'),
            min($jour, (int) $date->format('t')),
        );
    }
}
