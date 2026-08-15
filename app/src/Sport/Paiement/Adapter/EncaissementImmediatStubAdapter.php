<?php

declare(strict_types=1);

namespace App\Sport\Paiement\Adapter;

use App\Sport\Paiement\Dto\InitiationPaiementResultat;
use App\Sport\Paiement\Dto\ResultatConfirmationPaiement;
use App\Sport\Paiement\Port\EncaissementImmediatInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adaptateur d'encaissement CB par défaut — stub (Risque n°4 du plan), même patron que
 * `App\Compta\Adapter\PayFipStubAdapter` : référence déterministe, confirmation simulée synchrone.
 */
final class EncaissementImmediatStubAdapter implements EncaissementImmediatInterface
{
    public function initierPaiement(Uuid $incidentId, int $montantCentimes): InitiationPaiementResultat
    {
        $reference = 'CB1CLIC-' . substr(hash('sha256', (string) $incidentId . $montantCentimes), 0, 16);

        return new InitiationPaiementResultat(
            referenceTransaction: $reference,
            urlPaiement: 'https://paiement-cb.example.test/regler/' . $reference,
        );
    }

    public function confirmerPaiement(string $referenceTransaction): ResultatConfirmationPaiement
    {
        return new ResultatConfirmationPaiement(confirme: true, dateConfirmation: new \DateTimeImmutable());
    }
}
