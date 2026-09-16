<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupBooking;
use App\Group\Enum\GroupBookingStatus;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\JaugeRessourceMereHandler;
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
        private readonly JaugeRessourceMereHandler $jaugeMere,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupBooking
    {
        \assert($data instanceof GroupBooking);

        foreach ($data->getJaugeReservations() as $reservation) {
            if ($reservation->getStatut() === StatutReservation::Confirmee) {
                $reservation->setStatut(StatutReservation::AnnuleeLibre);
                // La jauge globale de la ressource se rend aussi (RG-M5-08) : la confirmation l'a
                // prise, l'annulation la rend. Sans ce geste, le compteur garderait les entrées d'un
                // groupe qui n'occupe plus rien.
                $porteuse = $reservation->getCreneau()?->getRessource();
                if ($porteuse !== null) {
                    $this->jaugeMere->decrementer($porteuse, $reservation->getQuantity());
                }
            }
        }

        $data->setStatus(GroupBookingStatus::Cancelled);
        $this->em->flush();

        return $data;
    }
}
