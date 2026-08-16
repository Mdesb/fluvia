<?php

declare(strict_types=1);

namespace App\Patinoire\Enum;

/** Mode de calcul de la grille de retenue (cahier §7, non tranché entre les deux → les deux existent). */
enum ModeRetenue: string
{
    case Forfait = 'forfait';
    case ValeurRemplacement = 'valeur_remplacement';
}
