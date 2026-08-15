<?php

declare(strict_types=1);

namespace App\Piscine\Enum;

/** État d'un casier connecté (US-L6-09, cahier §4). */
enum EtatCasier: string
{
    case Libre = 'libre';
    case Occupe = 'occupe';
    case NonRendu = 'non_rendu';
}
