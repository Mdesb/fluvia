<?php

declare(strict_types=1);

namespace App\Compta\Enum;

/**
 * Qualification SPIC/SPA d'un équipement (point EXPERT #1, §4.1 spec) : conditionne M4 vs M57 pour
 * les écritures qui s'y rattachent. Décision actée « paramétrable par équipement ».
 */
enum Qualification: string
{
    case Spic = 'SPIC';
    case Spa = 'SPA';
}
