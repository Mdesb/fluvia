<?php

declare(strict_types=1);

namespace App\OptionProduit\Enum;

/**
 * Nature de l'impact tarifaire d'une `ValeurOption` (RG-OPT-04) : montant fixe (€) ou pourcentage du
 * prix de base résolu par la grille M1 (`RG-M1-01`). Enum dédié au module (domaine distinct de
 * `App\Vente\Enum\RemiseType`).
 */
enum ImpactOptionType: string
{
    case Montant = 'montant';
    case Pourcentage = 'pourcentage';
}
