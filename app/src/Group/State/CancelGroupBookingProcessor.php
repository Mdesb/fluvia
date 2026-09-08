<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupBooking;
use App\Group\Enum\GroupBookingStatus;
use App\Reservation\Enum\StatutReservation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Annule une réservation de groupe (POST /group/bookings/{id}/cancel) : passage à `Cancelled`.
 * Définitif (une réservation annulée ne revient pas) ; idempotent.
 *
 * ── LIBÈRE LA JAUGE ─────────────────────────────────────────────────────────────────────────────
 * Si une réservation socle avait été créée à la confirmation pour décompter le créneau, on la passe à
 * `AnnuleeLibre` : ses places redeviennent disponibles. Sans ça, un groupe annulé continuerait de
 * bloquer la jauge d'un créneau qu'il n'occupe plus.
 *
 * @implements ProcessorInterface<GroupBooking, GroupBooking>
 */
final class CancelGroupBookingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupBooking
    {
        \assert($data instanceof GroupBooking);

        $reservation = $data->getJaugeReservation();
        if ($reservation !== null && $reservation->getStatut() === StatutReservation::Confirmee) {
            $reservation->setStatut(StatutReservation::AnnuleeLibre);
        }

        $data->setStatus(GroupBookingStatus::Cancelled);
        $this->em->flush();

        return $data;
    }
}
