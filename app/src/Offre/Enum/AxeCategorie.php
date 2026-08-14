<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Axe d'un plan de catégories (RG-M1-05). L'axe comptable est obligatoire pour publier.
 */
enum AxeCategorie: string
{
    case Marketing = 'marketing';
    case Comptable = 'comptable';
    case Rayon = 'rayon';
}
