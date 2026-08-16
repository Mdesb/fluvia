<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Mode d'agrégation ascendante d'un `Indicateur` (RG-M7-02/03). */
enum ModeCalculIndicateur: string
{
    case Somme = 'somme';
    case Max = 'max';
    case Moyenne = 'moyenne';
    case Ratio = 'ratio';
}
