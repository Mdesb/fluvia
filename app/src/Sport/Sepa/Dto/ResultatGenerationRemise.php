<?php

declare(strict_types=1);

namespace App\Sport\Sepa\Dto;

/** Résultat de la génération d'un lot pain.008 simulé (§2.1 du plan). */
final class ResultatGenerationRemise
{
    public function __construct(
        public readonly string $referenceRemise,
        public readonly int $nbEcheances,
        public readonly int $montantTotalCentimes,
    ) {
    }
}
