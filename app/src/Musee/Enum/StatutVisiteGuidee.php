<?php

declare(strict_types=1);

namespace App\Musee\Enum;

/** Cycle de vie d'une visite guidée (RG-MUS-02). */
enum StatutVisiteGuidee: string
{
    case Planifiee = 'planifiee';
    case Confirmee = 'confirmee';
    case Annulee = 'annulee';
}
