<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Mode de calcul du montant d'une facturation no-show (RG-M5-09). */
enum ModeMontantAnnulation: string
{
    case Fixe = 'fixe';
    case Pourcentage = 'pourcentage';
}
