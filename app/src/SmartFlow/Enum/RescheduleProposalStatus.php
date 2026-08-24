<?php

declare(strict_types=1);

namespace App\SmartFlow\Enum;

/**
 * Cycle de vie d'une `RescheduleProposal` (RG-SF-08..12, plan-smart-flow.md §1). Valeurs anglaises
 * (D5), identiques à la spec (spec-smart-flow.md §5).
 */
enum RescheduleProposalStatus: string
{
    /** Aucun créneau compatible trouvé pour l'instant (RG-SF-11) — relancé à chaque `slot.released` (I2). */
    case Searching = 'searching';

    /** Un créneau compatible a été trouvé et le client a été notifié (RG-SF-09/10). */
    case Proposed = 'proposed';

    /** Le client a confirmé sa nouvelle réservation via le chemin normal puis appelé `/accept` (RG-SF-12). */
    case Confirmed = 'confirmed';

    /** Refusée explicitement (`/decline`) ou expirée sans confirmation (RG-SF-11/12). */
    case Expired = 'expired';
}
