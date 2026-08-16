<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Motif de récurrence d'un Créneau (RG-M5-07). */
enum MotifRecurrence: string
{
    case Hebdomadaire = 'hebdomadaire';
    case Quotidien = 'quotidien';
    case Mensuel = 'mensuel';
}
