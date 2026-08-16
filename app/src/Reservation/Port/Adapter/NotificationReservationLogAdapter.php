<?php

declare(strict_types=1);

namespace App\Reservation\Port\Adapter;

use App\Reservation\Entity\ListeAttente;
use App\Reservation\Entity\Reservation;
use App\Reservation\Port\NotificationReservationInterface;
use Psr\Log\LoggerInterface;

/** Implémentation par défaut : journalise (aucun canal email/SMS/push spécifié dans ce dépôt). */
final class NotificationReservationLogAdapter implements NotificationReservationInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notifierPromotionListeAttente(ListeAttente $inscription): void
    {
        $this->logger->info('reservation.notification.promotion_liste_attente', [
            'listeAttente' => (string) $inscription->getId(),
            'creneau' => (string) $inscription->getCreneau()?->getId(),
            'beneficiaire' => (string) $inscription->getBeneficiaire()?->getId(),
        ]);
    }

    public function notifierArbitrageRecurrence(Reservation $reservation, string $motif): void
    {
        $this->logger->info('reservation.notification.arbitrage_recurrence', [
            'reservation' => (string) $reservation->getId(),
            'motif' => $motif,
        ]);
    }
}
