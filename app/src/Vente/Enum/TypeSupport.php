<?php

declare(strict_types=1);

namespace App\Vente\Enum;

/**
 * Type de support d'accès émis avec un billet/abonnement (cahier M2-§4).
 */
enum TypeSupport: string
{
    case Billet = 'billet';
    case Carte = 'carte';
    case Qr = 'qr';
    case Bracelet = 'bracelet';
    case Wallet = 'wallet';
}
