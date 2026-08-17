<?php

declare(strict_types=1);

namespace App\Stock\Enum;

/** Cycle de vie d'une réception d'achat (RG-STOCK-05). */
enum StatutReceptionAchat: string
{
    case Brouillon = 'brouillon';
    case Validee = 'validee';
}
