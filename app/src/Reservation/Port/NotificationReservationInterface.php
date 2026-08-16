<?php

declare(strict_types=1);

namespace App\Reservation\Port;

use App\Reservation\Entity\ListeAttente;
use App\Reservation\Entity\Reservation;

/**
 * Notification du bénéficiaire (promotion liste d'attente CA-5, report/arbitrage récurrence CA-7).
 * Hors périmètre socle L0 (canal email/SMS/push non spécifié dans ce dépôt) : implémentation par
 * défaut journalise uniquement (`NotificationReservationLogAdapter`).
 */
interface NotificationReservationInterface
{
    public function notifierPromotionListeAttente(ListeAttente $inscription): void;

    public function notifierArbitrageRecurrence(Reservation $reservation, string $motif): void;
}
