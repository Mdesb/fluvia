<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/**
 * Sens effectif d'un passage (entrée/sortie) : impact FMI ±1 (RG-ACC-04).
 */
enum SensPassage: string
{
    case Entree = 'entree';
    case Sortie = 'sortie';
}
