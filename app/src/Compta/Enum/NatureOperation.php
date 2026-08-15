<?php

declare(strict_types=1);

namespace App\Compta\Enum;

/** Nature d'opération comptable : détermine le journal cible (`RegimeComptableInterface::journalPour`). */
enum NatureOperation: string
{
    case Ventes = 'ventes';
    case Encaissements = 'encaissements';
    case Regie = 'regie';
    case PcaOd = 'pca_od';
    case Extourne = 'extourne';
}
