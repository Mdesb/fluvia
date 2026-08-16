<?php

declare(strict_types=1);

namespace App\Reservation\Facturation;

/**
 * Agrège les stratégies taguées `reservation.strategie_facturation_no_show` (décision structurante
 * n°4 du plan), indexées par `RegleAnnulation.modeFacturation`.
 */
final class ResolveurStrategieFacturation
{
    /** @var array<string, StrategieFacturationNoShow> */
    private array $index = [];

    /** @param iterable<StrategieFacturationNoShow> $strategies */
    public function __construct(iterable $strategies)
    {
        foreach ($strategies as $strategie) {
            $this->index[$strategie->code()] = $strategie;
        }
    }

    public function pour(string $modeFacturation): ?StrategieFacturationNoShow
    {
        return $this->index[$modeFacturation] ?? null;
    }
}
