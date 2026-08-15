<?php

declare(strict_types=1);

namespace App\Compta\Port;

/**
 * Port PayFiP (US-L4-03, RG-PAYFIP-03). ⚠ HYPOTHÈSE — protocole technique exact non détaillé dans
 * les sources (§9 du plan) : implémentation par défaut = `App\Compta\Adapter\PayFipStubAdapter`.
 */
interface PayFipInterface
{
    /** Initie une transaction PayFiP, renvoie l'URL de redirection et la référence de transaction. */
    public function initierPaiement(string $venteId, int $montantCentimes): PayFipInitiationResultat;
}
