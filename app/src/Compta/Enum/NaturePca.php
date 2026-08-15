<?php

declare(strict_types=1);

namespace App\Compta\Enum;

/** Nature d'un étalement PCA (RG-PCA-05), héritée de `Produit::reglePca` (M1, RG-M1-08). */
enum NaturePca: string
{
    case AEtaler = 'a_etaler';
    case ALaConsommation = 'a_la_consommation';
}
