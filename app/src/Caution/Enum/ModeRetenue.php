<?php

declare(strict_types=1);

namespace App\Caution\Enum;

/** Mode de calcul d'une ligne de grille de retenue (repris de `App\Patinoire\Enum\ModeRetenue`). */
enum ModeRetenue: string
{
    case Forfait = 'forfait';
    case ValeurRemplacement = 'valeur_remplacement';
}
