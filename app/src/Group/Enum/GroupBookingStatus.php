<?php

declare(strict_types=1);

namespace App\Group\Enum;

/**
 * Cycle de vie d'une réservation de groupe.
 *
 * `Option` = pré-réservation posée, susceptible d'expirer (`optionExpiresAt`) ; `Confirmed` = engagée ;
 * `Cancelled` = abandonnée. ⚠ `Cancelled` n'est PAS une pause : une réservation annulée ne revient pas.
 */
enum GroupBookingStatus: string
{
    case Option = 'option';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
