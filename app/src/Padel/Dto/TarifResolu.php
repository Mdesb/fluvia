<?php

declare(strict_types=1);

namespace App\Padel\Dto;

use App\Padel\Enum\StatutJoueurTarif;

/** Résultat de résolution tarifaire (§1.2 du plan-padel, `CalculateurTarifTerrainHandler`). */
final readonly class TarifResolu
{
    public function __construct(
        public string $prix,
        public StatutJoueurTarif $statutJoueur,
        public string $libellePlage,
    ) {
    }
}
