<?php

declare(strict_types=1);

namespace App\Piscine\Enum;

/** Public d'un créneau bassin (RG-PISC-03, US-L6-06) : plusieurs publics sur des lignes distinctes. */
enum TypePublic: string
{
    case GrandPublic = 'grand_public';
    case Scolaire = 'scolaire';
    case Club = 'club';
}
