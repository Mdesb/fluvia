<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Format d'un tournoi (US-PADEL-05). */
enum FormatTournoi: string
{
    case Poules = 'poules';
    case Tableau = 'tableau';
}
