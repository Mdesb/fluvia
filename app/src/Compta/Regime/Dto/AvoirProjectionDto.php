<?php

declare(strict_types=1);

namespace App\Compta\Regime\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Projection en lecture seule d'un `App\Vente\Entity\Avoir` (M2), pendant côté vente de l'extourne
 * comptable (RG-M2-07/§4.2).
 */
final class AvoirProjectionDto
{
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $venteOrigine,
        public readonly int $montantCentimes,
        public readonly string $motif,
        public readonly \DateTimeImmutable $dateHeure,
    ) {
    }
}
