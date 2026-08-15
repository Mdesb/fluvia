<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Statut d'un déclenchement du bouton SOS (US-SPORT-09). */
enum StatutEvenementSOS: string
{
    case Ouverte = 'ouverte';
    case Traitee = 'traitee';
}
