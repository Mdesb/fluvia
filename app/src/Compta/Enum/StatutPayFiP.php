<?php

declare(strict_types=1);

namespace App\Compta\Enum;

enum StatutPayFiP: string
{
    case Ok = 'ok';
    case Echec = 'echec';
    case Annule = 'annule';
    case EnAttente = 'en_attente';
}
