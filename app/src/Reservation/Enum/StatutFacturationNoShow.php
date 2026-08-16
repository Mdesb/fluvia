<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Statut d'une FacturationNoShow (RG-M5-09). */
enum StatutFacturationNoShow: string
{
    case AFacturer = 'a_facturer';
    case Facturee = 'facturee';
    case Exoneree = 'exoneree';
    case Contestee = 'contestee';
}
