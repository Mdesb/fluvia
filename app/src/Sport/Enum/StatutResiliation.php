<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Statut d'une demande de résiliation (RG-SPORT-06/07). */
enum StatutResiliation: string
{
    case EnPreavis = 'en_preavis';
    case Effective = 'effective';
    case Refusee = 'refusee';
}
