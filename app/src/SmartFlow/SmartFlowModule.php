<?php

declare(strict_types=1);

namespace App\SmartFlow;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\SmartFlow` (plan-smart-flow.md §0.11) — incrément **I1 seulement** à ce
 * stade (report de no-show, SF-2, RG-SF-08..12) ; `permissions()`/`eventsConsumed()`/`features()`
 * seront étendus par I2 (créneaux libérés + liste d'attente) et I3 (affluence), pas anticipés ici — le
 * manifeste est une déclaration de ce qui existe réellement (même discipline que `App\Dms\DmsModule`).
 *
 * `capability()` → `null` : service réactif transverse, Smart Flow ne se vend pas, il réagit à des
 * événements déjà produits par des modules payants (D22 « Smart Flow ne vend rien et ne facture rien
 * lui-même ») — ⚠ à trancher (alternative triviale : `CapaciteCode::SmartFlow`, plan §7 risque n°6).
 *
 * `dependencies()` → `[]` (transitoire, plan §7 risque n°7) : `reservation`/`acces` n'implémentent pas
 * encore `ModuleManifest`, les déclarer ferait échouer `ModuleRegistry::assertDependenciesAreResolved()`.
 *
 * `routes()` → `[]` (D13) : aucun écran dédié — modales ouvertes depuis l'espace de travail
 * `Reservation` existant côté agent, et depuis la notification reçue côté client (même choix
 * qu'`OcrModule`).
 */
final class SmartFlowModule implements ModuleManifest
{
    public function id(): string
    {
        return 'smart_flow';
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
            'smart_flow.reschedule_manage',
            'smart_flow.reschedule_read_own',
        ];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        // `slot.released` est posé en I2 — absent tant que I2 n'est pas livré (§0.11 du plan).
        return [];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        return [
            'booking.reschedule_requested',
        ];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [
            'no_show_reschedule',
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
        return [
            'compatibleSlotSearchWindowDays' => 14,
            'rescheduleProposalExpirationDays' => 30,
            'toleranceLevel' => 'same_type',
        ];
    }
}
