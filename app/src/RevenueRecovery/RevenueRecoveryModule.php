<?php

declare(strict_types=1);

namespace App\RevenueRecovery;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\RevenueRecovery` (plan-revenue-recovery.md §0.1/§3, id `revenue_recovery` —
 * ⚠ nom/namespace confirmé par les arbitrages de claude-A, cf. consignes de ce lot). Incrément **I1**
 * uniquement (moteur générique + quatre déclencheurs réellement câblés : `booking.cancelled`,
 * `booking.no_show`, `payment.failed`, `payment.incident_reopened`) — I2 (quatre déclencheurs bloqués
 * par RR-1 : `cart.abandoned`, `invoice.overdue`, `quote.sent`/`quote.expired`, `customer.inactive`)
 * reste hors périmètre de ce lot, non déclarée ici tant qu'elle n'est pas réellement câblée (le
 * manifeste est une déclaration de ce qui existe réellement, même discipline que `App\SmartFlow\SmartFlowModule`).
 *
 * `capability()` → `null` : service réactif transverse (comme Smart Flow, D22) — Revenue Recovery ne se
 * vend pas, il réagit à des événements déjà produits par des modules payants.
 *
 * `eventsEmitted()` → `[]` **volontairement** : les cinq événements `revenue_recovery.*` (§8 spec) ne
 * figurent pas encore à `COORDINATION/CONTRACT/catalogue-evenements.md` — les émettre ferait refuser le
 * push (garde-fou « charges utiles »/« orphelins »). Le cœur du moteur (`RecoveryEngine`) fonctionne
 * sans émettre — cf. les `TODO(claude-A catalogue)` laissés dans `RecoveryEngine` aux points d'émission
 * futurs (T9, différé).
 */
final class RevenueRecoveryModule implements ModuleManifest
{
    public function id(): string
    {
        return 'revenue_recovery';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return [
            'revenue_recovery.read',
            'revenue_recovery.configure',
            'revenue_recovery.manage',
        ];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        // Émission différée (T9, hors périmètre I1) : les 5 événements revenue_recovery.* ne sont pas
        // encore au catalogue partagé. À déclarer ici une fois ajoutés par claude-A.
        return [];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        return [
            // I1 — déclencheurs réellement émis (24/08).
            'booking.cancelled',
            'booking.no_show',
            'payment.failed',
            'payment.incident_reopened',
            // Résolution automatique (RG-RR-04) — seul `payment.succeeded` est réellement émis
            // aujourd'hui (LegacyEventBridge). `quote.accepted`/`sale.completed`/`booking.created` seront
            // ajoutés avec leur émetteur (RR-1/SF-1) — pas d'abonné inerte (D22).
            'payment.succeeded',
        ];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [
            'revenue_recovery',
        ];
    }

    /** @return list<string> */
    public function routes(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
