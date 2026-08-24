<?php

declare(strict_types=1);

namespace App\SmartFlow\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Lecture immuable d'un `App\Reservation\Entity\Creneau` (plan-smart-flow.md §0.3) — jamais une entité
 * Doctrine d'un autre module, uniquement les champs nécessaires à `CompatibleSlotFinder` (RG-SF-09) et
 * à la traçabilité d'une `RescheduleProposal`. Rendu par `App\SmartFlow\Service\ReservationSlotReader`
 * uniquement après revérification de l'établissement (RG-SF-16).
 */
final class SlotSnapshot
{
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $establishmentId,
        public readonly ?Uuid $resourceId,
        public readonly ?string $codeType,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
        public readonly int $residualCapacity,
    ) {
    }
}
