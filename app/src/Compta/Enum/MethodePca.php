<?php

declare(strict_types=1);

namespace App\Compta\Enum;

/**
 * Méthode de reconnaissance PCA (RG-M6-03) : prorata temporis (abonnement) ou au passage (carte
 * multi-entrées). ⚠ HYPOTHÈSE — grille exhaustive au-delà de ces deux cas non fournie (spec §9.3).
 */
enum MethodePca: string
{
    case ProrataTemporis = 'prorata_temporis';
    case AuPassage = 'au_passage';
}
