<?php

declare(strict_types=1);

namespace App\Piscine\Enum;

/** Granularité de la capacité d'accueil réglementaire POSS (RG-PISC-01, §4.2) : établissement ou bassin. */
enum PerimetrePoss: string
{
    case Etablissement = 'etablissement';
    case Bassin = 'bassin';
}
