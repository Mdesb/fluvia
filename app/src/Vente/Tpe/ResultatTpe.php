<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use App\Vente\Enum\StatutTPE;

/**
 * Ce que rend un terminal de paiement (US-L2-07 ; spec TPE lot 1 §3). Seul un statut « accepte »
 * crée un règlement — du montant RÉELLEMENT débité, qui peut être inférieur au montant demandé (le
 * reste dû reste alors ouvert) ; refus, annulation et timeout laissent le reste dû inchangé.
 *
 * Tout est stocké sur la tentative ; le ticket client s'imprime avec le ticket de caisse.
 */
final class ResultatTpe
{
    public function __construct(
        public readonly StatutTPE $statut,
        /** La référence portée par le règlement : n° de transaction, ou celle du ticket CB recopiée par le caissier. */
        public readonly ?string $reference = null,
        /** Décimal (« 30.00 ») ; nul = le montant demandé. */
        public readonly ?string $montantDebite = null,
        public readonly ?string $numeroAutorisation = null,
        public readonly ?string $transactionTerminal = null,
        public readonly ?string $identifiantTerminal = null,
        /** « CB », « international »… tel que le terminal le dit. */
        public readonly ?string $reseau = null,
        public readonly ?string $ticketClient = null,
        public readonly ?string $ticketCommercant = null,
    ) {
    }

    public function estAccepte(): bool
    {
        return $this->statut === StatutTPE::Accepte;
    }
}
