<?php

declare(strict_types=1);

namespace App\Compta\Regime\Dto;

use App\Offre\Enum\ReglePca;
use Symfony\Component\Uid\Uuid;

/**
 * Projection en lecture seule d'une `App\Vente\Entity\LigneVente` (M2), montants convertis en
 * centimes par `ProjectionVenteDoctrineAdapter` (frontière de conversion decimal → centimes).
 */
final class LigneVenteProjectionDto
{
    public function __construct(
        public readonly Uuid $produit,
        public readonly ?Uuid $categorieComptable,
        public readonly int $montantTtcCentimes,
        public readonly ReglePca $reglePca,
        public readonly ?\DateInterval $dureeValidite,
        public readonly ?int $nbCrediteCarte,
        public readonly ?string $identifiantSupport,
    ) {
    }
}
