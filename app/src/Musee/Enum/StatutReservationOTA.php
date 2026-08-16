<?php

declare(strict_types=1);

namespace App\Musee\Enum;

/** Sémantique OTA additive (US-MUSEE-08) — ne duplique pas `Reservation.statut` du module socle. */
enum StatutReservationOTA: string
{
    case Confirmee = 'confirmee';
    case RefuseeConflit = 'refusee_conflit';
    case RecupereeNoShow = 'recuperee_no_show';
}
