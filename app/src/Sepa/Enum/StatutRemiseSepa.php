<?php

declare(strict_types=1);

namespace App\Sepa\Enum;

/** Cycle de vie d'une remise pain.008 (générique, reprend `App\Sport\Enum\StatutRemiseSepa`). */
enum StatutRemiseSepa: string
{
    case Brouillon = 'brouillon';
    case Generee = 'generee';
    case Transmise = 'transmise';
}
