<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Issue d'un passage (§4.3/4.4 de la spec) : validé, refusé ou compté (non nominatif). */
enum ResultatPassage: string
{
    case Valide = 'valide';
    case Refuse = 'refuse';
    case Compte = 'compte';
}
