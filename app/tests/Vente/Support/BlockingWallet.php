<?php

declare(strict_types=1);

namespace App\Tests\Vente\Support;

use App\Vente\Port\PorteMonnaieVirtuelInterface;
use App\Vente\Port\ResultatDebitPmv;
use App\Vente\Port\SoldePmv;
use Symfony\Component\Uid\Uuid;

/**
 * Le porte-monnaie, qui s'arrête JUSTE APRÈS son débit dans l'autre processus d'un test de
 * concurrence : la fenêtre où un second appel trouvait la garde ouverte et débitait à son tour.
 * Partout ailleurs, il laisse passer (`SettlementBarrier`).
 */
final class BlockingWallet implements PorteMonnaieVirtuelInterface
{
    public function __construct(private readonly PorteMonnaieVirtuelInterface $inner)
    {
    }

    public function solde(Uuid $clientId): ?SoldePmv
    {
        return $this->inner->solde($clientId);
    }

    public function debiter(Uuid $clientId, string $montant, Uuid $venteId): ResultatDebitPmv
    {
        $resultat = $this->inner->debiter($clientId, $montant, $venteId);
        SettlementBarrier::hold('wallet');

        return $resultat;
    }

    public function crediter(Uuid $clientId, string $montant, Uuid $venteId, string $motif): void
    {
        $this->inner->crediter($clientId, $montant, $venteId, $motif);
    }

    public function recreditePourVente(Uuid $clientId, Uuid $venteId): string
    {
        return $this->inner->recreditePourVente($clientId, $venteId);
    }
}
