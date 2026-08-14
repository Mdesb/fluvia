<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Cycle de vie d'un produit (RG-M1-09). Transitions gérées par TransitionProduitHandler.
 */
enum StatutProduit: string
{
    case Brouillon = 'brouillon';
    case Publie = 'publie';
    case Archive = 'archive';
}
