<?php

declare(strict_types=1);

namespace App\Patinoire\Enum;

/** Cycle de vie d'un affûtage (RG-PAT-06, §4.6). */
enum StatutAffutage: string
{
    case EnAttente = 'en_attente';
    case EnCours = 'en_cours';
    case Termine = 'termine';
}
