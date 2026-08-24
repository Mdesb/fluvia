<?php

declare(strict_types=1);

namespace App\SmartFlow\EventListener;

use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\SmartFlow\Entity\SlotReleaseTrace;
use App\SmartFlow\Service\ReservationSlotReader;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Abonné à `booking.cancelled`/`booking.no_show` (RG-SF-01..04, plan-smart-flow.md T8) : relit la
 * disponibilité **réelle** du créneau via `ReservationSlotReader` (RG-SF-02 — la promotion de la liste
 * d'attente **interne** à `App\Reservation`, `PromotionListeAttenteHandler`, s'exécute déjà, synchrone,
 * depuis `AnnulerReservationProcessor`/`BasculerNoShowCommand`, **avant** que l'événement n'atteigne le
 * bus : ce listener ne suppose jamais que la place reste libre du seul fait que l'événement est arrivé).
 *
 * Écrit une `SlotReleaseTrace` de façon **idempotente** : tentative d'`INSERT`, une violation de la
 * contrainte unique `(slot_id, trigger_subject_id)` signale que cette libération précise a déjà été
 * traitée — arrêt silencieux, pas de republication (§0.4 du plan, RG-SF-04). Publie `slot.released`
 * (payload plat `{slotId, resourceId}`, §0.5 du plan — écart signalé au catalogue partagé) seulement si
 * la place est réellement libre après relecture (RG-SF-03).
 *
 * Best-effort (D7, même patron que `RescheduleRequestedListener`) : jamais de propagation d'exception
 * vers l'émetteur (`AnnulerReservationProcessor`/`BasculerNoShowCommand`).
 */
final class SlotFreedListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationSlotReader $slotReader,
        private readonly EventBus $eventBus,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            'booking.cancelled' => 'onSlotPotentiallyFreed',
            'booking.no_show' => 'onSlotPotentiallyFreed',
        ];
    }

    public function onSlotPotentiallyFreed(DomainEvent $event): void
    {
        try {
            $this->handle($event);
        } catch (\Throwable $error) {
            // Jamais de propagation : l'action métier qui a déclenché ce listener (annulation, constat
            // de no-show) doit aboutir même si Smart Flow échoue à traiter la libération de créneau.
            $this->logger?->error('smart_flow.listener.failed', [
                'event' => $event->name->value,
                'reason' => $error->getMessage(),
            ]);
        }
    }

    private function handle(DomainEvent $event): void
    {
        $establishment = $this->em->getRepository(Etablissement::class)->find($event->tenant->establishmentId);
        if (!$establishment instanceof Etablissement) {
            return;
        }

        $slotId = $this->uuid($event->payload['slotId'] ?? null);
        if ($slotId === null) {
            return;
        }

        $triggerSubjectId = $this->uuid($event->subject->id);
        if ($triggerSubjectId === null) {
            return;
        }

        // RG-SF-02/RG-SF-16 : relecture de la disponibilité réelle — jamais une supposition. Un slot
        // hors périmètre/introuvable est une donnée absente, traitement silencieux (échec fermé).
        $snapshot = $this->slotReader->snapshotCreneau($slotId, $event->tenant->establishmentId);
        if ($snapshot === null || $snapshot->resourceId === null) {
            return;
        }

        $released = $snapshot->residualCapacity > 0;

        $trace = new SlotReleaseTrace();
        $trace->setEstablishment($establishment)
            ->setSlotId($slotId)
            ->setResourceId($snapshot->resourceId)
            ->setTriggerEventName($event->name->value)
            ->setTriggerSubjectId($triggerSubjectId)
            ->setReleased($released);

        $this->em->persist($trace);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // RG-SF-04 : cette libération (slotId, triggerSubjectId) a déjà été traitée — arrêt
            // silencieux, aucune republication. Détache l'entité rejetée pour laisser l'EntityManager
            // utilisable par l'émetteur pour le reste de sa transaction (même patron que
            // `App\Dms\Processor\IssuePublicLinkProcessor`).
            $this->em->detach($trace);

            return;
        }

        if (!$released) {
            // RG-SF-02/03 : la liste d'attente interne à App\Reservation avait déjà repris la place.
            return;
        }

        $this->eventBus->publish(new DomainEvent(
            'slot.released',
            $event->tenant,
            new EventSubject('Slot', (string) $slotId),
            [
                // Clés `slot`/`resource` conformes au catalogue (`catalogue-evenements.md`, garde-fou
                // « charges utiles »). Valeurs = UUID en chaîne, pas d'objet imbriqué (RG-PLAT-04).
                'slot' => (string) $slotId,
                'resource' => (string) $snapshot->resourceId,
            ],
        ));
    }

    private function uuid(mixed $value): ?Uuid
    {
        return \is_string($value) && Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }
}
