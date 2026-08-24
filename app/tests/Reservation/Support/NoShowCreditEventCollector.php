<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Support;

use App\Platform\Event\DomainEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Collecteur des événements `booking.no_show`/`booking.cancelled`/`booking.reschedule_requested` pour
 * les tests CQ-5 (registré uniquement en environnement `test`, `app/config/services.yaml` bloc
 * `when@test:`) — même patron que `App\Tests\Acces\Support\CardRechargedEventCollector`.
 */
final class NoShowCreditEventCollector implements EventSubscriberInterface
{
    /** @var list<DomainEvent> */
    private array $noShow = [];

    /** @var list<DomainEvent> */
    private array $cancelled = [];

    /** @var list<DomainEvent> */
    private array $rescheduleRequested = [];

    public static function getSubscribedEvents(): array
    {
        return [
            'booking.no_show' => 'onNoShow',
            'booking.cancelled' => 'onCancelled',
            'booking.reschedule_requested' => 'onRescheduleRequested',
        ];
    }

    public function onNoShow(DomainEvent $event): void
    {
        $this->noShow[] = $event;
    }

    public function onCancelled(DomainEvent $event): void
    {
        $this->cancelled[] = $event;
    }

    public function onRescheduleRequested(DomainEvent $event): void
    {
        $this->rescheduleRequested[] = $event;
    }

    /** @return list<DomainEvent> */
    public function evenementsNoShow(): array
    {
        return $this->noShow;
    }

    /** @return list<DomainEvent> */
    public function evenementsCancelled(): array
    {
        return $this->cancelled;
    }

    /** @return list<DomainEvent> */
    public function evenementsRescheduleRequested(): array
    {
        return $this->rescheduleRequested;
    }

    public function reset(): void
    {
        $this->noShow = [];
        $this->cancelled = [];
        $this->rescheduleRequested = [];
    }
}
