<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Types de promotions (RG-M1-04). « bonus_10_12 » = compostages bonus d'une carte multi-entrées.
 */
enum TypePromotion: string
{
    case Pourcentage = 'pourcentage';
    case Montant = 'montant';
    case OffreGroupee = 'offre_groupee';
    case Bonus1012 = 'bonus_10_12';
}
