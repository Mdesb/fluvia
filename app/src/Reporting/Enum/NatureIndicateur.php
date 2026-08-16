<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Distingue une mesure instantanée (FMI) d'une mesure cumulée (CA, fréquentation) — RG-M7-04. */
enum NatureIndicateur: string
{
    case Instantane = 'instantane';
    case Cumule = 'cumule';
}
