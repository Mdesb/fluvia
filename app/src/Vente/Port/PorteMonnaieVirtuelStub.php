<?php

declare(strict_types=1);

namespace App\Vente\Port;

use Symfony\Component\Uid\Uuid;

/**
 * Stub L2 du port PMV (M4) : aucun solde connu, tout débit est refusé — ne bloque pas les tests M2
 * existants qui ne couvrent pas le moyen `pmv` en détail (§2.1 plan-crm.md).
 */
final class PorteMonnaieVirtuelStub implements PorteMonnaieVirtuelInterface
{
    public function solde(Uuid $clientId): ?SoldePmv
    {
        return new SoldePmv(existe: false);
    }

    public function debiter(Uuid $clientId, string $montant, Uuid $venteId): ResultatDebitPmv
    {
        return new ResultatDebitPmv(reussi: false, motifRefus: 'PMV indisponible (stub L2).');
    }

    public function crediter(Uuid $clientId, string $montant, Uuid $venteId, string $motif): void
    {
        // no-op : stub L2, aucun solde à créditer.
    }
}
