<?php

declare(strict_types=1);

namespace App\Vente\Enum;

/**
 * Nature d'une remise de ligne (cahier M2-02/03) : montant fixe en € ou pourcentage.
 */
enum RemiseType: string
{
    case Montant = 'montant';
    case Pourcentage = 'pourcentage';
}
