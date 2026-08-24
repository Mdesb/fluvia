<?php

declare(strict_types=1);

namespace App\Social\Crypto;

use App\Securite\Crypto\ChiffreurSecret;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Coffre à jetons du module de publication sociale (D14, SOC-1) — chiffrement réversible au repos des
 * jetons d'accès et de rafraîchissement des comptes connectés.
 *
 * **Réutilise** `App\Securite\Crypto\ChiffreurSecret` (libsodium `crypto_secretbox`, nonce par
 * message) plutôt que de poser un quatrième mécanisme : il y en a déjà trois de la même forme
 * (secret MFA, IBAN SEPA, clé d'API OCR), et un chiffrement de plus est une surface de plus à
 * auditer pour zéro gain.
 *
 * **Clé dédiée par usage** (invariant noyau commun #4) : `SOCIAL_TOKEN_ENCRYPTION_KEY`, distincte de
 * `MFA_ENCRYPTION_KEY` / `SEPA_IBAN_KEY` / `OCR_API_KEY_ENCRYPTION_KEY`. Compromettre le coffre social
 * ne doit rien donner sur le reste. **Aucun repli codé en dur** : si la variable est absente, le
 * conteneur refuse de démarrer — échec fermé (D3), jamais un chiffrement de façade.
 *
 * Ce que ce coffre ne fait pas, et c'est délibéré : il ne journalise rien. Un jeton ne sort jamais
 * d'ici — ni dans une réponse d'API, ni dans un événement, ni dans un journal.
 */
final class SocialTokenCipher
{
    private readonly ChiffreurSecret $cipher;

    public function __construct(
        #[Autowire(env: 'SOCIAL_TOKEN_ENCRYPTION_KEY')]
        string $keyBase64,
    ) {
        $this->cipher = new ChiffreurSecret($keyBase64);
    }

    public function encrypt(string $plain): string
    {
        return $this->cipher->chiffrer($plain);
    }

    public function decrypt(string $value): string
    {
        return $this->cipher->dechiffrer($value);
    }
}
