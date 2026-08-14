<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Mode au dépassement du seuil FMI (RG-ACC-04, décision actée) : blocage strict ou simple alerte. */
enum ModeSeuil: string
{
    case Blocage = 'blocage';
    case Alerte = 'alerte';
}
