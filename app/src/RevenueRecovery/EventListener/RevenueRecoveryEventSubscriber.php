<?php

declare(strict_types=1);

namespace App\RevenueRecovery\EventListener;

use App\Platform\Event\DomainEvent;
use App\RevenueRecovery\Enum\RecoveryTriggerType;
use App\RevenueRecovery\Service\RecoveryEngine;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Point d'entrée unique du déclenchement automatique (§0.7 du plan) : s'abonne aux quatre déclencheurs
 * **réellement émis** au 24/08 (I1 — `booking.cancelled`, `booking.no_show`, `payment.failed`,
 * `payment.incident_reopened`) et aux événements de résolution correspondants (RG-RR-04, table §6 de la
 * spec), délègue systématiquement à `RecoveryEngine::handle()`/`resolve()` — jamais de logique
 * métier ici.
 *
 * **N'émet aucun `revenue_recovery.*`** (émission différée, T9 hors périmètre de ce lot — cf. consignes
 * de la mission).
 *
 * **Best-effort obligatoire (D7, patron `App\SmartFlow\EventListener\SlotFreedListener`/
 * `App\Platform\Event\Legacy\LegacyEventBridge`).** Le bus est synchrone et propage les exceptions à
 * l'émetteur (RG-PLAT-05, ex. `AnnulerReservationProcessor`/`BasculerNoShowCommand`) : chaque méthode
 * capture ses propres erreurs et journalise, jamais de propagation vers l'action métier d'origine.
 */
final class RevenueRecoveryEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RecoveryEngine $engine,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            // I1 — déclencheurs réellement émis (24/08, spec §10).
            'booking.cancelled' => 'onBookingCancelled',
            'booking.no_show' => 'onBookingNoShow',
            'payment.failed' => 'onPaymentFailed',
            'payment.incident_reopened' => 'onPaymentIncidentReopened',
            // Résolution automatique (RG-RR-04) — seuls les événements de résolution **réellement émis**
            // sont abonnés (sinon « abonné inerte », garde-fou orphelins / D22). `payment.succeeded` l'est
            // (via LegacyEventBridge). `quote.accepted`/`sale.completed`/`booking.created` ne sont pas
            // encore émis (RR-1/SF-1, hors périmètre) : abonnement différé, à ajouter avec leur émetteur.
            'payment.succeeded' => 'onResolutionEvent',
        ];
    }

    /**
     * Relance conditionnée à `withinFreeWindow` (payload catalogue, spec §6) — une annulation dans la
     * fenêtre gratuite est le seul cas I1 où `RevenueRecovery` ouvre un dossier sur `booking.cancelled`.
     */
    public function onBookingCancelled(DomainEvent $event): void
    {
        $this->guard($event, function () use ($event): void {
            if (true !== ($event->payload['withinFreeWindow'] ?? null)) {
                return;
            }
            $this->engine->handle($event, RecoveryTriggerType::BookingCancelled, $this->intOrNull($event->payload['creditRestoredAmount'] ?? null));
        });
    }

    public function onBookingNoShow(DomainEvent $event): void
    {
        $this->guard($event, function () use ($event): void {
            $this->engine->handle($event, RecoveryTriggerType::BookingNoShow, $this->intOrNull($event->payload['amountAtRisk'] ?? null));
        });
    }

    public function onPaymentFailed(DomainEvent $event): void
    {
        $this->guard($event, function () use ($event): void {
            $this->engine->handle($event, RecoveryTriggerType::PaymentFailed, $this->intOrNull($event->payload['amount_cents'] ?? null));
        });
    }

    public function onPaymentIncidentReopened(DomainEvent $event): void
    {
        $this->guard($event, function () use ($event): void {
            $this->engine->handle($event, RecoveryTriggerType::PaymentIncidentReopened, $this->intOrNull($event->payload['amount_cents'] ?? null));
        });
    }

    /**
     * RG-RR-04 : clôt tout `RecoveryCase` actif dont `(establishment, subjectType, subjectRef)`
     * correspond au sujet de l'événement de résolution reçu — dégradation propre si rien ne correspond
     * (`RecoveryEngine::resolve()` renvoie 0, aucune exception).
     */
    public function onResolutionEvent(DomainEvent $event): void
    {
        $this->guard($event, function () use ($event): void {
            $this->engine->resolve($event->tenant->establishmentId, $event->subject->type, $event->subject->id);
        });
    }

    private function guard(DomainEvent $event, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $error) {
            // Jamais de propagation : l'action métier qui a déclenché ce listener doit aboutir même si
            // Revenue Recovery échoue à traiter l'événement (D7).
            $this->logger?->error('revenue_recovery.listener.failed', [
                'event' => $event->name->value,
                'reason' => $error->getMessage(),
            ]);
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_numeric($value) ? (int) $value : null;
    }
}
