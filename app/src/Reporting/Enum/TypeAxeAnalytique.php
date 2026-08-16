<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/**
 * Axes analytiques disponibles (§4.3 spec, RG-M7-05). `categorie`/`canal` sont des extensions
 * (`AxeAnalytique.estExtension = true`), non littéralement au cahier M7 mais ajoutées par la spec.
 */
enum TypeAxeAnalytique: string
{
    case Site = 'site';
    case Activite = 'activite';
    case Produit = 'produit';
    case Categorie = 'categorie';
    case Periode = 'periode';
    case Canal = 'canal';
}
