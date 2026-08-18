<?php

declare(strict_types=1);

namespace App\OptionProduit\Enum;

/**
 * Mode de sélection d'un `GroupeOption` (RG-OPT-01) : choix unique (0 ou 1 valeur, ex. taille) ou
 * choix multiple (0..n valeurs, ex. extras).
 */
enum ModeSelectionOption: string
{
    case Unique = 'unique';
    case Multiple = 'multiple';
}
