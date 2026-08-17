<?php

declare(strict_types=1);

namespace App\Stock\Enum;

/** Méthode de valorisation des couches de coût (RG-STOCK-08). */
enum MethodeValorisation: string
{
    case Fifo = 'fifo';
    case Lifo = 'lifo';
}
