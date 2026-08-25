<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Unit;

use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\RevenueRecovery\EventListener\RevenueRecoveryEventSubscriber;
use App\RevenueRecovery\Enum\RecoveryTriggerType;
use App\RevenueRecovery\Service\RecoveryEngine;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `RevenueRecoveryEventSubscriber` (I1, plan-revenue-recovery.md §0.7/T7) — câblage des quatre
 * déclencheurs réellement émis + résolution automatique (RG-RR-04), et best-effort obligatoire (D7,
 * RG-PLAT-05, patron `App\Tests\SmartFlow\Unit\SmartFlowListenerBestEffortTest`).
 */
final class RevenueRecoveryEventSubscriberTest extends TestCase
{
    public function testGetSubscribedEventsCouvreLesQuatreDeclencheursI1EtLaResolution(): void
    {
        $souscrits = array_keys(RevenueRecoveryEventSubscriber::getSubscribedEvents());

        self::assertSame(
            [
                'booking.cancelled', 'booking.no_show', 'payment.failed', 'payment.incident_reopened',
                'payment.succeeded',
            ],
            $souscrits,
        );
    }

    /** Spec §6 : `booking.cancelled` n'ouvre un dossier que si `withinFreeWindow` est vrai. */
    public function testBookingCancelledOuvreCaseSiWithinFreeWindow(): void
    {
        $event = $this->evenement('booking.cancelled', ['withinFreeWindow' => true, 'creditRestoredAmount' => 1500]);

        $engine = $this->createMock(RecoveryEngine::class);
        $engine->expects(self::once())->method('handle')->with($event, RecoveryTriggerType::BookingCancelled, 1500);

        (new RevenueRecoveryEventSubscriber($engine))->onBookingCancelled($event);
    }

    public function testBookingCancelledHorsFenetreGratuiteNouvreAucunCase(): void
    {
        $event = $this->evenement('booking.cancelled', ['withinFreeWindow' => false]);

        $engine = $this->createMock(RecoveryEngine::class);
        $engine->expects(self::never())->method('handle');

        (new RevenueRecoveryEventSubscriber($engine))->onBookingCancelled($event);
    }

    public function testBookingNoShowOuvreCase(): void
    {
        $event = $this->evenement('booking.no_show', ['amountAtRisk' => 2500]);

        $engine = $this->createMock(RecoveryEngine::class);
        $engine->expects(self::once())->method('handle')->with($event, RecoveryTriggerType::BookingNoShow, 2500);

        (new RevenueRecoveryEventSubscriber($engine))->onBookingNoShow($event);
    }

    public function testPaymentFailedOuvreCase(): void
    {
        $event = $this->evenement('payment.failed', ['amount_cents' => 4200]);

        $engine = $this->createMock(RecoveryEngine::class);
        $engine->expects(self::once())->method('handle')->with($event, RecoveryTriggerType::PaymentFailed, 4200);

        (new RevenueRecoveryEventSubscriber($engine))->onPaymentFailed($event);
    }

    public function testPaymentIncidentReopenedRelanceComplementaire(): void
    {
        $event = $this->evenement('payment.incident_reopened', ['amount_cents' => 4200]);

        $engine = $this->createMock(RecoveryEngine::class);
        $engine->expects(self::once())->method('handle')->with($event, RecoveryTriggerType::PaymentIncidentReopened, 4200);

        (new RevenueRecoveryEventSubscriber($engine))->onPaymentIncidentReopened($event);
    }

    public function testEvenementDeResolutionAppelleResolveAvecLeSujetDeLevenement(): void
    {
        $tenant = Uuid::v4();
        $event = new DomainEvent(
            'payment.succeeded',
            new EventTenant($tenant),
            new EventSubject('PaymentIncident', 'incident-42'),
            [],
        );

        $engine = $this->createMock(RecoveryEngine::class);
        $engine->expects(self::once())->method('resolve')->with($tenant, 'PaymentIncident', 'incident-42');

        (new RevenueRecoveryEventSubscriber($engine))->onResolutionEvent($event);
    }

    /** D7/RG-PLAT-05 : une exception dans le moteur ne casse jamais la transaction de l'émetteur. */
    public function testExceptionDansLeMoteurNeCasseraJamaisLaTransactionEmetteur(): void
    {
        $event = $this->evenement('booking.no_show', ['amountAtRisk' => 1000]);

        $engine = $this->createMock(RecoveryEngine::class);
        $engine->method('handle')->willThrowException(new \RuntimeException('panne simulée'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('revenue_recovery.listener.failed');

        $subscriber = new RevenueRecoveryEventSubscriber($engine, $logger);

        // Ne doit lever aucune exception — capturée et journalisée (D7, best-effort).
        $subscriber->onBookingNoShow($event);

        self::addToAssertionCount(1);
    }

    public function testAucunLoggerNeLeveNonPlus(): void
    {
        $event = $this->evenement('booking.no_show', ['amountAtRisk' => 1000]);

        $engine = $this->createMock(RecoveryEngine::class);
        $engine->method('handle')->willThrowException(new \RuntimeException('panne simulée'));

        $subscriber = new RevenueRecoveryEventSubscriber($engine);

        $subscriber->onBookingNoShow($event);

        self::addToAssertionCount(1);
    }

    /** @param array<string, scalar|array|null> $payload */
    private function evenement(string $nom, array $payload): DomainEvent
    {
        return new DomainEvent(
            $nom,
            new EventTenant(Uuid::v4()),
            new EventSubject('Reservation', (string) Uuid::v4()),
            $payload,
        );
    }
}
