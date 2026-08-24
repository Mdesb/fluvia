<?php

declare(strict_types=1);

namespace App\SmartFlow\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\SmartFlow\Service\ReservationSlotReader;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /smart-flow/reschedule-proposals/{id}/accept` (RG-SF-12, plan-smart-flow.md §0.9) — ferme la
 * proposition, ne crée **jamais** de réservation (Smart Flow n'écrit jamais dans `App\Reservation`,
 * D2/§2 de la spec). Le client crée sa nouvelle réservation par le chemin normal
 * (`POST /reservation/reservations`, API existante, non modifiée), puis appelle cette route avec
 * `{ confirmedReservationRef: <iri|uuid> }` pour clore la proposition.
 *
 * Revérifie, via `ReservationSlotReader` (lecture seule), que la réservation référencée existe,
 * appartient au même établissement **et** au même `customerId` que la proposition — sinon 422 (IDOR,
 * même garde que partout ailleurs dans le dépôt, RG-SF-16).
 *
 * @implements ProcessorInterface<mixed, RescheduleProposal>
 */
final class AcceptRescheduleProposalProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ReservationSlotReader $slotReader,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RescheduleProposal
    {
        \assert($data instanceof RescheduleProposal);

        $corps = $this->lecteur->corps();
        $reservationId = $this->uuid($corps['confirmedReservationRef'] ?? null);
        if ($reservationId === null) {
            throw new UnprocessableEntityHttpException('Référence « confirmedReservationRef » obligatoire (UUID ou IRI).');
        }

        $establishment = $data->getEstablishment();
        if ($establishment === null) {
            throw new UnprocessableEntityHttpException('Proposition sans établissement.');
        }

        $snapshot = $this->slotReader->snapshotReservation($reservationId, $establishment->getId());
        if ($snapshot === null
            || $snapshot->customerId === null
            || !$snapshot->customerId->equals($data->getCustomerId())
        ) {
            throw new UnprocessableEntityHttpException(
                'La réservation référencée n\'appartient pas au même établissement/client que la proposition (IDOR, RG-SF-16).',
            );
        }

        $data->setConfirmedReservationRef($reservationId)
            ->setStatus(RescheduleProposalStatus::Confirmed);

        $this->em->flush();

        return $data;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || '' === $reference) {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
