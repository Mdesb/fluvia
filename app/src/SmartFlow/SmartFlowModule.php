<?php

declare(strict_types=1);

namespace App\SmartFlow;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\SmartFlow` (plan-smart-flow.md §0.11) — incréments **I1 + I2** à ce stade
 * (report de no-show, SF-2, RG-SF-08..12 ; créneaux libérés + liste d'attente, RG-SF-01..07) ; les
 * champs relatifs à I3 (affluence, `access.recorded`/`access.card_recharged`) suivront à T13, bloqués
 * par SF-1 — le manifeste est une déclaration de ce qui existe réellement (même discipline que
 * `App\Dms\DmsModule`).
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
            'smart_flow.read',
            'smart_flow.reschedule_manage',
            'smart_flow.reschedule_read_own',
        ];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        // `slot.released` (I2, RG-SF-03) — posé ici depuis T11, `SlotFreedListener` en est l'unique
        // producteur.
        return [
            'slot.released',
        ];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        return [
            'booking.reschedule_requested',
            // I2 (RG-SF-01..04) : `SlotFreedListener`.
            'booking.cancelled',
            'booking.no_show',
        ];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [
            'no_show_reschedule',
            // I2 : créneaux libérés + liste d'attente Smart Flow (RG-SF-01..07).
            'slot_recovery',
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
            // I2 (§0.7 du plan, RG-SF-07) : délai de confirmation d'une promotion de liste d'attente
            // Smart Flow avant de tenter l'inscription suivante.
            'slotWaitlistPromotionExpirationMinutes' => 15,
            'toleranceLevel' => 'same_type',
        ];
    }
}
