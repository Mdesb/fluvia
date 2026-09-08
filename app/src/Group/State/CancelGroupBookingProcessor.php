<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupBooking;
use App\Group\Enum\GroupBookingStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Annule une réservation de groupe (POST /group/bookings/{id}/cancel) : passage à `Cancelled`.
 * Définitif (une réservation annulée ne revient pas) ; idempotent.
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

        $data->setStatus(GroupBookingStatus::Cancelled);
        $this->em->flush();

        return $data;
    }
}
