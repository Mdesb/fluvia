<?php

declare(strict_types=1);

namespace App\Sport\Sepa\Adapter;

use App\Sport\Sepa\Dto\TokenIban;
use App\Sport\Sepa\Port\TokenisationIbanInterface;

/**
 * Adaptateur de tokenisation par défaut — HMAC-SHA256 avec clé d'application (Risque n°3 du plan).
 * ⚠ **Non un vrai coffre-fort de paiement (PCI-DSS)** — suffisant pour ne jamais exposer l'IBAN en
 * clair (garde testée), insuffisant pour une protection cryptographique de niveau production réelle.
 */
final class TokenisationIbanHmacAdapter implements TokenisationIbanInterface
{
    public function __construct(
        private readonly string $cle,
    ) {
    }

    public function tokeniser(string $ibanClair): TokenIban
    {
        $normalise = strtoupper(str_replace(' ', '', $ibanClair));
        $token = hash_hmac('sha256', $normalise, $this->cle);
        $quatreDerniers = substr($normalise, -4);

        return new TokenIban($token, $quatreDerniers);
    }
}
