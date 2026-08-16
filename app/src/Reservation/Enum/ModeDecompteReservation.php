<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Mode de décompte d'une réservation (RG-M5-02). */
enum ModeDecompteReservation: string
{
    case QuotaFormule = 'quota_formule';
    case VenteUnite = 'vente_unite';
    case Gratuit = 'gratuit';
}
