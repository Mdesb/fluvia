<?php

declare(strict_types=1);

namespace App\Sport\Paiement\Dto;

/** Résultat d'initiation d'un encaissement CB immédiat (résolution 1 clic, §2.3 du plan). */
final class InitiationPaiementResultat
{
    public function __construct(
        public readonly string $referenceTransaction,
        public readonly string $urlPaiement,
    ) {
    }
}
