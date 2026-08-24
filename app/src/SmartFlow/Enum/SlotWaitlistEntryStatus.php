<?php

declare(strict_types=1);

namespace App\SmartFlow\Enum;

/**
 * Cycle de vie d'une `SlotWaitlistEntry` (RG-SF-05..07, plan-smart-flow.md §1). Valeurs anglaises (D5),
 * identiques à la spec (spec-smart-flow.md §5).
 */
enum SlotWaitlistEntryStatus: string
{
    /** En attente d'un créneau libéré sur la ressource (RG-SF-05). */
    case Waiting = 'waiting';

    /** Promue à réception de `slot.released` (RG-SF-06) — une `RescheduleProposal` a été matérialisée. */
    case Promoted = 'promoted';

    /** La promotion (`RescheduleProposal` liée) a expiré sans confirmation (RG-SF-07). */
    case Expired = 'expired';

    /** Annulée par le bénéficiaire ou l'agent (hors périmètre de ce lot, réservée pour un futur usage). */
    case Cancelled = 'cancelled';
}
