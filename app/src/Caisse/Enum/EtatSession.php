<?php

declare(strict_types=1);

namespace App\Caisse\Enum;

/**
 * État d'une session de caisse (RG-M2-01/06) : ouverte → en_fermeture → close (irréversible).
 */
enum EtatSession: string
{
    case Ouverte = 'ouverte';
    case EnFermeture = 'en_fermeture';
    case Close = 'close';
}
