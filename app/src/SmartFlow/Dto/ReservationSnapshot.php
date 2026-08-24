<?php

declare(strict_types=1);

namespace App\SmartFlow\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Lecture immuable d'une `App\Reservation\Entity\Reservation` (plan-smart-flow.md §0.3/§0.9) — utilisée
 * par `AcceptRescheduleProposalProcessor` pour revérifier, sans jamais écrire dans `App\Reservation`,
 * que la réservation référencée par le client appartient au même établissement et au même client que
 * la `RescheduleProposal` (IDOR, RG-SF-16). Rendu par
 * `App\SmartFlow\Service\ReservationSlotReader::snapshotReservation()` uniquement après revérification
 * de l'établissement.
 */
final class ReservationSnapshot
{
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $establishmentId,
        public readonly ?Uuid $customerId,
        public readonly ?Uuid $slotId,
    ) {
    }
}
