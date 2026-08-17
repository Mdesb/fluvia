<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Statut d'un `JetonTerminal` (US-TERM-01/09) : révocation immédiate, sans période de grâce (Risque R-2). */
enum StatutJetonTerminal: string
{
    case Actif = 'actif';
    case Revoque = 'revoque';
}
