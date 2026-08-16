<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Statut du niveau de jeu déclaré (US-PADEL-04). */
enum StatutNiveauJoueur: string
{
    case Propose = 'propose';
    case Valide = 'valide';
}
