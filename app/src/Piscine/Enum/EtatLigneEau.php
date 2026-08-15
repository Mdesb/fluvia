<?php

declare(strict_types=1);

namespace App\Piscine\Enum;

/** État dérivé d'une ligne d'eau (US-L6-05/06) : disponible au grand public ou préemptée par une réservation. */
enum EtatLigneEau: string
{
    case Publique = 'publique';
    case Reservee = 'reservee';
}
