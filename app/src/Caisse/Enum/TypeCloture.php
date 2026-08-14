<?php

declare(strict_types=1);

namespace App\Caisse\Enum;

/**
 * Type de clôture (US-L2-11) : Z (par session), puis clôtures périodiques mensuelle/annuelle
 * (⚠ périmètre et déclenchement à cadrer avec M6).
 */
enum TypeCloture: string
{
    case Z = 'Z';
    case Mensuelle = 'mensuelle';
    case Annuelle = 'annuelle';
}
