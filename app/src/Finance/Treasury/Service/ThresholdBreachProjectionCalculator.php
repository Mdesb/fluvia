<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Service;

use App\Finance\Treasury\Dto\ThresholdBreachProjection;
use App\Organisation\Entity\Etablissement;

/**
 * Projection jour par jour du franchissement d'un seuil de trésorerie (§0.4 du plan, RG-TRE-11/12) —
 * nouveau calcul, mais qui ne réinvente ni la position ni l'échéancier : réutilise
 * `TreasuryPositionCalculator::position()`/`PaymentScheduleCalculator::echeancier()` tels quels, comme
 * `CashflowForecastCalculator` le fait déjà (RG-TRE-08).
 */
final class ThresholdBreachProjectionCalculator
{
    public function __construct(
        private readonly TreasuryPositionCalculator $positionCalculator,
        private readonly PaymentScheduleCalculator $scheduleCalculator,
    ) {
    }

    public function projeter(
        Etablissement $etablissement,
        \DateTimeImmutable $aujourdhui,
        int $thresholdCents,
        int $horizonDays,
    ): ThresholdBreachProjection {
        $etablissements = [$etablissement->getId()->toBinary()];

        $solde = $this->positionCalculator->position($etablissements, $aujourdhui)['balanceCents'];
        $echeancier = $this->scheduleCalculator->echeancier(
            $etablissements,
            $aujourdhui,
            $aujourdhui->modify(sprintf('+%d days', $horizonDays)),
        );

        // Fusionne entries[]/exits[] en mouvements signés, ignore les mouvements sans date (§0.4 point 3
        // du plan — un mouvement sans date ne peut pas être placé dans la projection jour par jour).
        /** @var list<array{date: string, amountCents: int, source: string, sourceId: string, isExit: bool}> $mouvements */
        $mouvements = [];
        foreach ($echeancier['entries'] as $entree) {
            if ($entree['date'] === null) {
                continue;
            }
            $mouvements[] = ['date' => $entree['date'], 'amountCents' => $entree['amountCents'], 'source' => $entree['source'], 'sourceId' => $entree['sourceId'], 'isExit' => false];
        }
        foreach ($echeancier['exits'] as $sortie) {
            if ($sortie['date'] === null) {
                continue;
            }
            $mouvements[] = ['date' => $sortie['date'], 'amountCents' => $sortie['amountCents'], 'source' => $sortie['source'], 'sourceId' => $sortie['sourceId'], 'isExit' => true];
        }

        // Tri stable (PHP usort stable depuis 8.0, §0.4 point 4 du plan).
        usort($mouvements, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        // Regroupe les mouvements de même date pour cumuler en une seule étape par date (la
        // granularité est la journée, pas l'événement, §0.4 point 4 du plan) : le seuil n'est évalué
        // qu'une fois tous les mouvements d'une même date appliqués, jamais entre deux mouvements du
        // même jour.
        $cumul = $solde;
        $breachDate = null;
        $nombreMouvements = \count($mouvements);
        $indice = 0;
        while ($indice < $nombreMouvements) {
            $date = $mouvements[$indice]['date'];
            while ($indice < $nombreMouvements && $mouvements[$indice]['date'] === $date) {
                $cumul += $mouvements[$indice]['isExit'] ? -$mouvements[$indice]['amountCents'] : $mouvements[$indice]['amountCents'];
                ++$indice;
            }

            // Strictement inférieur (RG-TRE-11 point 5) — une égalité au seuil n'est pas un franchissement.
            if ($cumul < $thresholdCents) {
                $breachDate = $date;
                break;
            }
        }

        if ($breachDate === null) {
            return ThresholdBreachProjection::aucunFranchissement();
        }

        // Cause principale (RG-TRE-12) : parmi les sorties dont la date est <= à la date de
        // franchissement, celle au montant le plus élevé ; égalité -> la plus ancienne (déjà garanti par
        // le tri stable ci-dessus, aucun second tri nécessaire).
        $causeSource = null;
        $causeSourceId = null;
        $causeAmountCents = null;
        $meilleurMontant = null;
        foreach ($echeancier['exits'] as $sortie) {
            if ($sortie['date'] === null || $sortie['date'] > $breachDate) {
                continue;
            }
            if ($meilleurMontant === null || $sortie['amountCents'] > $meilleurMontant) {
                $meilleurMontant = $sortie['amountCents'];
                $causeSource = $sortie['source'];
                $causeSourceId = $sortie['sourceId'];
                $causeAmountCents = $sortie['amountCents'];
            }
        }

        return new ThresholdBreachProjection(
            new \DateTimeImmutable($breachDate),
            $cumul,
            $causeSource,
            $causeSourceId,
            $causeAmountCents,
        );
    }
}
