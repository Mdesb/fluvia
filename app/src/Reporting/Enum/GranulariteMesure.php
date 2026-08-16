<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Granularité temporelle d'une `Mesure`/d'un `ObjectifIndicateur` (axe période, §4.3 spec). */
enum GranulariteMesure: string
{
    case Jour = 'jour';
    case Semaine = 'semaine';
    case Mois = 'mois';
    case Annee = 'annee';
}
