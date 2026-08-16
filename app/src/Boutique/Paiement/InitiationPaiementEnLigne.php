<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

final class InitiationPaiementEnLigne
{
    public function __construct(
        public readonly string $referenceTransaction,
        public readonly string $urlRedirection,
    ) {
    }
}
