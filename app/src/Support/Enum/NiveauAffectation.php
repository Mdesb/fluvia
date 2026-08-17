<?php

declare(strict_types=1);

namespace App\Support\Enum;

/** Niveau de support affecté à un `TicketSupport` (RG-SUP-13). */
enum NiveauAffectation: string
{
    case N1 = 'N1';
    case N2 = 'N2';
}
