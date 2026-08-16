<?php

declare(strict_types=1);

namespace App\Patinoire\Enum;

/**
 * Statut de la caution de location de patins (décision structurante plan §0 point 1) : enum **propre**
 * à la patinoire (4 valeurs), plus fin que `App\Piscine\Enum\StatutCaution` (3 valeurs) — pas de
 * mutualisation dans ce lot, patron dupliqué volontairement (spec §8 point 3).
 */
enum StatutCaution: string
{
    case Encaissee = 'encaissee';
    case Liberee = 'liberee';
    case RetenuePartielle = 'retenue_partielle';
    case RetenueTotale = 'retenue_totale';
}
