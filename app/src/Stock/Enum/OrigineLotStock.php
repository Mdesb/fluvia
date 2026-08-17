<?php

declare(strict_types=1);

namespace App\Stock\Enum;

/** Origine d'une couche de coût (`LotStock`), traçabilité RG-STOCK-08. */
enum OrigineLotStock: string
{
    case Reception = 'reception';
    case RegularisationInventaire = 'regularisation_inventaire';
    case EntreeTransfert = 'entree_transfert';
}
