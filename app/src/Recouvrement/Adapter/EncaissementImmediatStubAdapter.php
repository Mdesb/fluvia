<?php

declare(strict_types=1);

namespace App\Recouvrement\Adapter;

use App\Recouvrement\Dto\InitiationPaiementResultat;
use App\Recouvrement\Dto\ResultatConfirmationPaiement;
use App\Recouvrement\Port\EncaissementImmediatInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adaptateur d'encaissement CB par défaut — stub, référence déterministe, confirmation simulée
 * synchrone (ex-`App\Sport\Paiement\Adapter\EncaissementImmediatStubAdapter`, généralisé).
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
