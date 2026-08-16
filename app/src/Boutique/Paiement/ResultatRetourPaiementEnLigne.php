<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use App\Vente\Enum\StatutTPE;

final class ResultatRetourPaiementEnLigne
{
    public function __construct(
        public readonly string $referenceTransaction,
        public readonly StatutTPE $statut,
        public readonly int $montantCentimes,
    ) {
    }
}
