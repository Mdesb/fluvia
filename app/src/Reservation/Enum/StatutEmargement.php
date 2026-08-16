<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Statut d'un émargement (cahier M5-05). */
enum StatutEmargement: string
{
    case Present = 'present';
    case Absent = 'absent';
}
