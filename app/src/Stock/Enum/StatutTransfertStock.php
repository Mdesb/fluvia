<?php

declare(strict_types=1);

namespace App\Stock\Enum;

/** Cycle de vie d'un transfert inter-établissements (RG-STOCK-14). */
enum StatutTransfertStock: string
{
    case Demande = 'demande';
    case Expedie = 'expedie';
    case Recu = 'recu';
}
