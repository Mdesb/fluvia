<?php

declare(strict_types=1);

namespace App\Musee\Enum;

/** Motif d'une gratuité scolaire (RG-MUS-03). */
enum MotifGratuite: string
{
    case Eleve = 'eleve';
    case Accompagnateur = 'accompagnateur';
}
