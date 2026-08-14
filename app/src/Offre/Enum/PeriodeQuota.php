<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Période de décompte d'un service inclus (RG-M1-12). En L1, uniquement la semaine calendaire
 * (remise à zéro le lundi, fenêtre lundi→dimanche, sans report).
 */
enum PeriodeQuota: string
{
    case SemaineCalendaire = 'semaine_calendaire';
}
