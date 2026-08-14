<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Type de support d'accès (A-02) : QR imprimé, RFID (bracelet étanche piscine) ou wallet mobile. */
enum TypeSupport: string
{
    case Qr = 'QR';
    case Rfid = 'RFID';
    case Wallet = 'wallet';
}
