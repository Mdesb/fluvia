<?php

declare(strict_types=1);

namespace App\Compta\Adapter;

use App\Compta\Port\PayFipInitiationResultat;
use App\Compta\Port\PayFipInterface;

/**
 * Adaptateur PayFiP par défaut — stub (⚠ HYPOTHÈSE, protocole exact non détaillé dans les sources,
 * §9 du plan). Génère une référence de transaction déterministe et une URL de redirection factice.
 */
final class PayFipStubAdapter implements PayFipInterface
{
    public function initierPaiement(string $venteId, int $montantCentimes): PayFipInitiationResultat
    {
        $reference = 'PAYFIP-' . substr(hash('sha256', $venteId . $montantCentimes), 0, 16);

        return new PayFipInitiationResultat(
            referenceTransaction: $reference,
            urlRedirection: 'https://payfip.example.test/paiement/' . $reference,
        );
    }
}
