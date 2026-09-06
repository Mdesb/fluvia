<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

final class InitiationPaiementEnLigne
{
    /**
     * @param array<string, string>|null $simulationReceipts statut => reçu signé, rendus par un BOUCHON
     *                                                       seulement — un vrai prestataire n'en rend
     *                                                       aucun, c'est lui qui décide de l'issue
     */
    public function __construct(
        public readonly string $referenceTransaction,
        public readonly string $urlRedirection,
        public readonly ?array $simulationReceipts = null,
    ) {
    }
}
