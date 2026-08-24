<?php

declare(strict_types=1);

namespace App\SmartFlow\Port;

use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Entity\SlotWaitlistEntry;

/**
 * Notification du client (RG-SF-10, RG-SF-06/07) — port propre à Smart Flow (D19, plan-smart-flow.md
 * §0.8) : aucun port transverse de notification réutilisable trouvé au-delà de
 * `App\Reservation\Port\NotificationReservationInterface` (propre à `Reservation`, non réutilisable
 * sans coupler les deux modules, D2). Implémentation par défaut : journalise uniquement
 * (`ClientNotificationLogAdapter`, aucun canal email/SMS/push spécifié dans ce dépôt).
 */
interface ClientNotificationInterface
{
    /** Une proposition de report vient de passer `proposed` (RG-SF-09/10) : un créneau a été trouvé. */
    public function notifyRescheduleProposal(RescheduleProposal $proposal): void;

    /**
     * Une inscription liste d'attente Smart Flow vient d'être promue (RG-SF-06) : une `RescheduleProposal`
     * a été matérialisée (`$entry->getPromotedProposalRef()`), avec un délai de confirmation court
     * (RG-SF-07).
     */
    public function notifyWaitlistPromotion(SlotWaitlistEntry $entry): void;
}
