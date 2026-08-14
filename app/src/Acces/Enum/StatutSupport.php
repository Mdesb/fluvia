<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Statut d'un support (RG-ACC-07) : bloqué en cas de perte/vol, tout scan refusé y compris hors-ligne. */
enum StatutSupport: string
{
    case Actif = 'actif';
    case Bloque = 'bloque';
}
