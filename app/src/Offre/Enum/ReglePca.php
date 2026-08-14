<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Règle de produit constaté d'avance (RG-M1-08). M1 porte la règle sans l'exécuter (M6).
 */
enum ReglePca: string
{
    case Aucune = 'aucune';
    case Etalement = 'etalement';
    case Consommation = 'consommation';
}
