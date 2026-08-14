<?php

declare(strict_types=1);

namespace App\Vente\Enum;

/**
 * État d'appairage d'un support d'accès (RG-M2-04 / CA-12). Un échec bloque la remise du support
 * et est journalisé ; « invalide » = dévalidé après annulation post-impression (US-L2-09).
 */
enum StatutAppairage: string
{
    case EnAttente = 'en_attente';
    case Actif = 'actif';
    case Echec = 'echec';
    case Invalide = 'invalide';
}
