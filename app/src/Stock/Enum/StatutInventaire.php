<?php

declare(strict_types=1);

namespace App\Stock\Enum;

/** Cycle de vie d'un inventaire physique (RG-STOCK-12, append-only après clôture). */
enum StatutInventaire: string
{
    case EnCours = 'en_cours';
    case EnValidation = 'en_validation';
    case Cloture = 'cloture';
}
