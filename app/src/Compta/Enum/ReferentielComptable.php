<?php

declare(strict_types=1);

namespace App\Compta\Enum;

/**
 * Référentiel comptable de rattachement (RG-COMPTA-01). Verrouillé après la première clôture
 * (ProfilExploitant::verrouille).
 */
enum ReferentielComptable: string
{
    case M57 = 'M57';
    case M4Spic = 'M4_SPIC';
    case Pcg = 'PCG';
}
