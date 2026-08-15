<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Cycle de vie d'un lot de prélèvements (remise pain.008 simulée, Risque n°2 du plan). */
enum StatutRemiseSepa: string
{
    case Brouillon = 'brouillon';
    case Generee = 'generee';
    case Transmise = 'transmise';
    case Traitee = 'traitee';
}
