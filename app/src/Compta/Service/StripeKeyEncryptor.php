<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Securite\Crypto\ChiffreurSecret;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement réversible des clés Stripe (clé secrète, secret de webhook) saisies par un exploitant.
 *
 * Enveloppe `App\Securite\Crypto\ChiffreurSecret` (libsodium `crypto_secretbox`) avec une clé
 * maîtresse dédiée à cet usage : `STRIPE_KEYS_ENCRYPTION_KEY`, 32 octets en base64 (strict), générée par le
 * déploiement. **Aucune valeur par défaut.** Patron de `App\Ocr\Service\ChiffreurApiKeyOcr`.
 *
 * Décision de Maxime (21/11/2026) : la clé maîtresse est **facultative** (`default::` : absente = `null`,
 * le conteneur démarre quand même). Une valeur qui ne décode pas en exactement 32 octets est traitée comme
 * absente (jamais de dérivation silencieuse, contrairement à `ChiffreurSecret`). Sans clé exploitable,
 * `encrypt()` et `decrypt()` lèvent
 * `StripeKeyEncryptionUnavailableException` **à l'usage** (jamais au démarrage) ; seul le repli global
 * (clés d'environnement) reste possible. Jamais de chiffrement de façade avec une clé par défaut.
 *
 * Les exceptions de `ChiffreurSecret` et de ce service ne citent jamais la valeur.
 */
final class StripeKeyEncryptor
{
    private readonly ?ChiffreurSecret $cipher;

    public function __construct(
        #[Autowire(env: 'default::STRIPE_KEYS_ENCRYPTION_KEY')]
        #[\SensitiveParameter]
        ?string $masterKeyBase64,
    ) {
        $raw = ($masterKeyBase64 === null || $masterKeyBase64 === '') ? false : base64_decode($masterKeyBase64, true);
        $this->cipher = ($raw !== false && \strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) ? new ChiffreurSecret($masterKeyBase64) : null;
    }

    /** Vrai si la clé maîtresse est posée et bien formée (base64, 32 octets) : sans elle, aucune clé d'exploitant ne peut être stockée ni lue. */
    public function isAvailable(): bool
    {
        return $this->cipher !== null;
    }

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        return $this->cipher()->chiffrer($plain);
    }

    public function decrypt(string $encrypted): string
    {
        return $this->cipher()->dechiffrer($encrypted);
    }

    private function cipher(): ChiffreurSecret
    {
        return $this->cipher ?? throw new StripeKeyEncryptionUnavailableException();
    }
}
