<?php

declare(strict_types=1);

namespace App\Recouvrement\Dto;

/** Résultat d'initiation d'un encaissement CB immédiat (résolution 1 clic, moteur partagé). */
final class InitiationPaiementResultat
{
    public function __construct(
        public readonly string $referenceTransaction,
        public readonly string $urlPaiement,
    ) {
    }
}
