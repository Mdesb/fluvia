<?php

declare(strict_types=1);

namespace App\Sepa\Adapter;

use App\Sepa\Dto\TokenIban;
use App\Sepa\Port\TokenisationIbanInterface;

/**
 * Adaptateur de tokenisation par défaut — HMAC-SHA256 avec clé d'application (repris de
 * `App\Sport\Sepa\Adapter\TokenisationIbanHmacAdapter`, Risque n°3 du plan-sport).
 * ⚠ **Non un vrai coffre-fort de paiement (PCI-DSS)** — suffisant pour ne jamais exposer l'IBAN en
 * clair en API (garde testée), insuffisant pour une protection cryptographique de niveau production
 * réelle. Ce jeton n'est **pas réversible** — il sert uniquement à l'affichage/recherche (4 derniers
 * chiffres). La reconstruction du véritable IBAN pour le contenu du fichier pain.008 est assurée
 * séparément par un coffre **réversible** (`App\Sepa\Service\ChiffreurIbanInterface`, libsodium), dont
 * la valeur chiffrée est déchiffrée uniquement par `Pain008Generator`, côté serveur.
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
