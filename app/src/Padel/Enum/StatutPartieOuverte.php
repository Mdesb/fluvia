<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Statut d'une partie ouverte (matching de joueurs), RG-PADEL-03. */
enum StatutPartieOuverte: string
{
    case Ouverte = 'ouverte';
    case Complete = 'complete';
    case MaintenueA3 = 'maintenue_a_3';
}
