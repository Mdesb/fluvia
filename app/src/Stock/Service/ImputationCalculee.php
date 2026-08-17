<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Stock\Entity\LotStock;

/** Une imputation calculée (avant persistance) : lot consommé, quantité prélevée, coût unitaire du lot. */
final class ImputationCalculee
{
    public function __construct(
        public readonly LotStock $lot,
        public readonly string $quantite,
        public readonly string $coutUnitaire,
    ) {
    }
}
