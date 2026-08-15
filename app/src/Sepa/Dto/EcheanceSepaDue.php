<?php

declare(strict_types=1);

namespace App\Sepa\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Échéance due, fournie au module SEPA par une verticale via `EcheanceSepaSource` (plan §3/§5).
 * `referenceOrigine` est un identifiant opaque (choisi par la verticale, ex. UUID de son échéance)
 * qui lui est retourné par `EcheanceSepaSource::marquerCollectees()` — le module SEPA ne connaît
 * aucun type métier de la verticale appelante (Sport, Piscine…).
 */
final class EcheanceSepaDue
{
    public function __construct(
        public readonly string $referenceOrigine,
        public readonly Uuid $mandatId,
        public readonly int $montantCentimes,
        public readonly string $libelle,
        public readonly \DateTimeImmutable $dateEcheance,
        public readonly bool $derniereEcheanceEngagement = false,
        public readonly bool $paiementUnique = false,
    ) {
    }
}
