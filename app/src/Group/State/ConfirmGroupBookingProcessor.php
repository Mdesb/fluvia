<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupBooking;
use App\Group\Enum\GroupBookingStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Confirme une réservation de groupe (POST /group/bookings/{id}/confirm) : `Option` → `Confirmed`.
 * Une réservation annulée ne se confirme pas (`Cancelled` est définitif, pas une pause). Idempotent sur
 * une réservation déjà confirmée.
 *
 * @implements ProcessorInterface<GroupBooking, GroupBooking>
 */
final class ConfirmGroupBookingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupBooking
    {
        \assert($data instanceof GroupBooking);

        if ($data->getStatus() === GroupBookingStatus::Cancelled) {
            throw new UnprocessableEntityHttpException('Une réservation annulée ne peut pas être confirmée.');
        }

        $data->setStatus(GroupBookingStatus::Confirmed);
        $this->em->flush();

        return $data;
    }
}
