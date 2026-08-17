<?php

declare(strict_types=1);

namespace App\Personnel\Enum;

/** Motif de récurrence d'un CreneauTravail (RG-PERSO-03, généralisation RG-M5-07). */
enum MotifRecurrenceTravail: string
{
    case Hebdomadaire = 'hebdomadaire';
}
