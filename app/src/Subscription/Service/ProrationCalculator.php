<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Subscription\Exception\InvalidPeriodException;

/**
 * Prorata d'une option ajoutée en cours de période (ED-2, CA-4).
 *
 * **Pourquoi une classe pour trois lignes d'arithmétique.** Parce que ces trois lignes décident de ce
 * qu'on prélève sur le compte d'un client. Isolées ici, elles sont testables sur les bornes exactes —
 * premier jour, dernier jour, mois de 28 et de 31 jours — au lieu d'être enfouies dans un service de
 * facturation où personne ne les relit.
 *
 * **Tout est en centimes entiers.** Un flottant sur des fractions de mois produit des écarts
 * invisibles à l'affichage et bien réels sur un relevé bancaire.
 *
 * **La journée d'ajout est due en entier.** Un client qui active une option le 15 à 23 h paie le 15 :
 * on compte des jours, pas des heures. C'est la convention la plus simple à expliquer au téléphone,
 * et c'est celle qui joue en faveur du client sur la question inverse — la journée de retrait.
 */
final class ProrationCalculator
{
    /**
     * Montant dû pour une option active de `$activeFrom` jusqu'à la fin de la période.
     *
     * @param int $fullPriceCents prix plein de la période complète
     *
     * @throws InvalidPeriodException si la période est vide ou inversée
     */
    public function forPartialPeriod(
        int $fullPriceCents,
        \DateTimeImmutable $activeFrom,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): int {
        if ($periodEnd <= $periodStart) {
            throw new InvalidPeriodException(sprintf(
                'Période de facturation invalide : la fin (%s) doit être postérieure au début (%s).',
                $periodEnd->format('Y-m-d'),
                $periodStart->format('Y-m-d'),
            ));
        }

        if ($fullPriceCents <= 0) {
            return 0;
        }

        // Activée avant le début : la période entière est due. On ne facture jamais plus que le
        // prix plein, quelle que soit l'ancienneté de la date fournie.
        if ($activeFrom <= $periodStart) {
            return $fullPriceCents;
        }

        // Activée après la fin : rien n'est dû sur cette période — l'option sera facturée sur la
        // suivante, pas rétroactivement sur celle-ci.
        if ($activeFrom >= $periodEnd) {
            return 0;
        }

        $joursTotal = $this->joursEntre($periodStart, $periodEnd);
        $joursRestants = $this->joursEntre($activeFrom, $periodEnd);

        if ($joursTotal <= 0) {
            return 0;
        }

        // Arrondi au centime le plus proche. Le sens de l'arrondi doit être **constant** : alterner
        // selon les cas produirait des totaux qui ne se recomposent pas d'une facture à l'autre.
        return (int) round($fullPriceCents * $joursRestants / $joursTotal);
    }

    /**
     * Nombre de jours entiers entre deux instants, la journée de départ comptant pour une journée due.
     *
     * On normalise à minuit avant de compter : sans cela, deux activations le même jour à des heures
     * différentes donneraient deux montants différents, ce qui est indéfendable devant un client.
     */
    private function joursEntre(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        $d = $debut->setTime(0, 0);
        $f = $fin->setTime(0, 0);

        return (int) $d->diff($f)->days;
    }
}
