<?php

declare(strict_types=1);

namespace App\Stock\Enum;

/** Unité de mesure d'un article de stock (US-STOCK-01). */
enum Unite: string
{
    case Piece = 'piece';
    case Kg = 'kg';
    case Litre = 'litre';
    case Paquet = 'paquet';
    case Autre = 'autre';
}
