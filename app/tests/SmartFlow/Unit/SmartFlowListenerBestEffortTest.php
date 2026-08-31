<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Unit;

use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Platform\Notification\ClientNotifierInterface;
use App\SmartFlow\EventListener\RescheduleRequestedListener;
use App\SmartFlow\Service\CompatibleSlotFinder;
use App\SmartFlow\Service\ReservationSlotReader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * D7/best-effort (plan-smart-flow.md §0.10, T4) — une exception dans le listener ne casse jamais la
 * transaction de l'émetteur (`AnnulerReservationProcessor`/`BasculerNoShowCommand`) : capturée et
 * journalisée uniquement (patron `App\Platform\Event\Legacy\LegacyEventBridge`).
 */
final class SmartFlowListenerBestEffortTest extends TestCase
{
    public function testExceptionDansLeListenerNeCasseraJamaisLaTransactionEmetteur(): void
    {
        // `createStub` (pas `createMock`) : on ne vérifie aucun appel sur l'EM, on a juste besoin qu'il
        // lève — un simulacre exprimerait une attente inutile (D20 : verdict de référence = zéro notice).
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willThrowException(new \RuntimeException('panne simulée'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('smart_flow.listener.failed');

        $listener = new RescheduleRequestedListener(
            $em,
            new ReservationSlotReader($em),
            new CompatibleSlotFinder(),
            $this->createStub(ClientNotifierInterface::class),
            $logger,
        );

        $event = new DomainEvent(
            'booking.reschedule_requested',
            new EventTenant(Uuid::v4()),
            new EventSubject('Reservation', (string) Uuid::v4()),
            [
                'customerId' => (string) Uuid::v4(),
                'reservationRef' => (string) Uuid::v4(),
                'slotId' => (string) Uuid::v4(),
                'droitId' => (string) Uuid::v4(),
            ],
        );

        // Ne doit lever aucune exception — capturée et journalisée (D7, best-effort).
        $listener->onRescheduleRequested($event);

        self::addToAssertionCount(1);
    }

    public function testAucunLoggerNeLeveNonPlus(): void
    {
        // `createStub` (pas `createMock`) : on ne vérifie aucun appel sur l'EM, on a juste besoin qu'il
        // lève — un simulacre exprimerait une attente inutile (D20 : verdict de référence = zéro notice).
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willThrowException(new \RuntimeException('panne simulée'));

        $listener = new RescheduleRequestedListener(
            $em,
            new ReservationSlotReader($em),
            new CompatibleSlotFinder(),
            $this->createStub(ClientNotifierInterface::class),
            // logger absent (nullable, §T4) : ne doit pas non plus faire planter le listener.
        );

        $event = new DomainEvent(
            'booking.reschedule_requested',
            new EventTenant(Uuid::v4()),
            new EventSubject('Reservation', (string) Uuid::v4()),
            [
                'customerId' => (string) Uuid::v4(),
                'reservationRef' => (string) Uuid::v4(),
                'slotId' => (string) Uuid::v4(),
                'droitId' => (string) Uuid::v4(),
            ],
        );

        $listener->onRescheduleRequested($event);

        self::addToAssertionCount(1);
    }
}
