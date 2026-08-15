<?php

declare(strict_types=1);

namespace App\Sepa\Adapter;

use App\Sepa\Dto\TokenIban;
use App\Sepa\Port\TokenisationIbanInterface;

/**
 * Adaptateur de tokenisation par défaut — HMAC-SHA256 avec clé d'application (repris de
 * `App\Sport\Sepa\Adapter\TokenisationIbanHmacAdapter`, Risque n°3 du plan-sport).
 * ⚠ **Non un vrai coffre-fort de paiement (PCI-DSS)** — suffisant pour ne jamais exposer l'IBAN en
 * clair (garde testée), insuffisant pour une protection cryptographique de niveau production réelle.
 *
 * Le jeton n'étant pas réversible, `Pain008Generator` ne peut pas reconstruire l'IBAN réel pour le
 * contenu du fichier XML : il reconstruit un IBAN de type placeholder (préfixe pays + zéros + 4
 * derniers chiffres connus), à l'image des échantillons de référence anonymisés eux-mêmes (dont les
 * IBAN sont déjà fictifs, zero-paddés). Une remise bancaire réelle nécessiterait un coffre IBAN
 * PCI-DSS complet — explicitement hors périmètre (§9 du plan).
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
