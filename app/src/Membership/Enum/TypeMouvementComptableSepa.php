<?php

declare(strict_types=1);

namespace App\Membership\Enum;

/** Nature du mouvement journalisé dans la file d'attente comptable transitoire (§1.8/§2.4 du plan). */
enum TypeMouvementComptableSepa: string
{
    case Encaissement = 'encaissement';
    case Impaye = 'impaye';
}
