<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/** Vivacité d'un relais d'éclairage (RG-PADEL-05). */
enum StatutRelaisEclairage: string
{
    case Operationnel = 'operationnel';
    case EnDefaut = 'en_defaut';
}
