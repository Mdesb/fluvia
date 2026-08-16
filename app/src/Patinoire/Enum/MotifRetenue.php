<?php

declare(strict_types=1);

namespace App\Patinoire\Enum;

/** Motif d'application de la grille de retenue (décision actée « patins non rendus/cassés », §4.4). */
enum MotifRetenue: string
{
    case Casse = 'casse';
    case NonRendu = 'non_rendu';
    case Perte = 'perte';
    case RestitutionPartielle = 'restitution_partielle';
}
