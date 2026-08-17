<?php

declare(strict_types=1);

namespace App\Personnel\Enum;

/** Mode horaire du badge staff (RG-PERSO-06/07, §4.7 spec, décisions n°3/4 du plan). */
enum ModeHoraireBadge: string
{
    case ShiftsUniquement = 'shifts_uniquement';
    case Permanent = 'permanent';
}
