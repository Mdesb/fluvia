<?php

declare(strict_types=1);

namespace App\Compta\Enum;

/** Cycle de vie exercice (§4.10 spec, RG-CLOTURE-10) : clôture irréversible. */
enum StatutPeriode: string
{
    case Ouverte = 'ouverte';
    case Cloturee = 'cloturee';
}
