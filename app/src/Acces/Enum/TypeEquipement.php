<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Nature de l'équipement (US-L3-01) : tourniquet/tripode iDTRONIC ou lecteur QR/RFID. */
enum TypeEquipement: string
{
    case Tourniquet = 'tourniquet';
    case Tripode = 'tripode';
    case Lecteur = 'lecteur';
}
