<?php

declare(strict_types=1);

namespace App\Stock\Enum;

/** Périmètre d'un inventaire physique (RG-STOCK-12). */
enum PerimetreInventaire: string
{
    case Tous = 'tous';
    case Rayon = 'rayon';
    case Selection = 'selection';
}
