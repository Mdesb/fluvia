<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Service;

/**
 * Prévisionnel simple (§0.8 du plan, RG-TRE-08) — `projectedBalance = position(aujourd'hui).balance +
 * Σ entries[≤ horizon] − Σ exits[≤ horizon]` : **projection arithmétique brute**, aucune pondération de
 * probabilité de paiement, aucun scénario (optimiste/pessimiste) — hypothèse déjà actée par la spec
 * elle-même (RG-TRE-08). Réutilise `TreasuryPositionCalculator`/`PaymentScheduleCalculator` tels quels,
 * aucun second calcul de solde/échéancier réinventé.
 */
final class CashflowForecastCalculator
{
    public function __construct(
        private readonly TreasuryPositionCalculator $positionCalculator,
        private readonly PaymentScheduleCalculator $scheduleCalculator,
    ) {
    }

    /**
     * @param list<string> $etablissements identifiants **binaires** (`Uuid::toBinary()`), comme `TreasuryPositionCalculator::position()`
     *
     * @return array{asOfDate: string, horizonDays: int, projectedBalanceCents: int}
     */
    public function projeter(array $etablissements, \DateTimeImmutable $aujourdhui, int $horizonDays): array
    {
        $position = $this->positionCalculator->position($etablissements, $aujourdhui);
        $echeancier = $this->scheduleCalculator->echeancier($etablissements, $aujourdhui, $aujourdhui->modify(sprintf('+%d days', $horizonDays)));

        $totalEntrees = array_sum(array_map(static fn (array $e) => $e['amountCents'], $echeancier['entries']));
        $totalSorties = array_sum(array_map(static fn (array $e) => $e['amountCents'], $echeancier['exits']));

        return [
            'asOfDate' => $aujourdhui->format('Y-m-d'),
            'horizonDays' => $horizonDays,
            'projectedBalanceCents' => $position['balanceCents'] + $totalEntrees - $totalSorties,
        ];
    }
}
