<?php

declare(strict_types=1);

namespace App\Securite\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement/déchiffrement réversible du secret MFA (RG-M8-06) via libsodium
 * (`sodium_crypto_secretbox`). Contrairement aux mots de passe/codes de récupération (hash
 * sha256, non réversibles), le secret TOTP doit être récupérable en clair pour vérifier un code.
 *
 * Clé : 32 octets, fournie en base64 par `MFA_ENCRYPTION_KEY` (env).
 */
final class ChiffreurSecret
{
    private string $cle;

    public function __construct(#[Autowire(env: 'MFA_ENCRYPTION_KEY')] string $cleBase64)
    {
        $cle = base64_decode($cleBase64, true);
        if ($cle === false || \strlen($cle) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            // Dérive une clé de 32 octets déterministe à partir de la valeur fournie plutôt que
            // d'échouer au démarrage (permet une valeur d'env non-base64 en dev/test).
            $cle = sodium_crypto_generichash($cleBase64, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }
        $this->cle = $cle;
    }

    public function chiffrer(string $clair): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = sodium_crypto_secretbox($clair, $nonce, $this->cle);

        return base64_encode($nonce . $chiffre);
    }

    public function dechiffrer(string $valeur): string
    {
        $binaire = base64_decode($valeur, true);
        if ($binaire === false || \strlen($binaire) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Valeur chiffrée invalide.');
        }

        $nonce = substr($binaire, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = substr($binaire, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $clair = sodium_crypto_secretbox_open($chiffre, $nonce, $this->cle);
        if ($clair === false) {
            throw new \RuntimeException('Déchiffrement impossible (clé ou valeur invalide).');
        }

        return $clair;
    }
}
