<?php

declare(strict_types=1);

namespace App\Sepa\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement/déchiffrement réversible de l'IBAN via libsodium (`sodium_crypto_secretbox`), même
 * patron que `App\Securite\Crypto\ChiffreurSecret` (secret MFA). Contrairement au jeton HMAC
 * (`TokenisationIbanHmacAdapter`, non réversible), ce coffre permet de reconstruire l'IBAN en clair
 * — strictement nécessaire pour que la remise pain.008 porte le véritable IBAN du débiteur/créancier.
 *
 * Clé : 32 octets, fournie en base64 par `SEPA_IBAN_KEY` (env). **En développement/test**, une valeur
 * de convenance est fournie dans `.env` (`SEPA_IBAN_KEY`, dérivée si non conforme base64/32 octets —
 * cf. constructeur). **En production**, cette clé doit provenir d'un secret/vault (ex. Symfony
 * secrets, Vault, KMS) et ne jamais être committée en clair — c'est un secret cryptographique au même
 * titre que `MFA_ENCRYPTION_KEY`.
 */
final class ChiffreurIban implements ChiffreurIbanInterface
{
    private string $cle;

    public function __construct(#[Autowire(env: 'SEPA_IBAN_KEY')] string $cleBase64)
    {
        $cle = base64_decode($cleBase64, true);
        if ($cle === false || \strlen($cle) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            // Dérive une clé de 32 octets déterministe à partir de la valeur fournie plutôt que
            // d'échouer au démarrage (permet une valeur d'env non-base64 en dev/test).
            $cle = sodium_crypto_generichash($cleBase64, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }
        $this->cle = $cle;
    }

    public function chiffrer(string $ibanClair): string
    {
        $normalise = strtoupper(str_replace(' ', '', $ibanClair));
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = sodium_crypto_secretbox($normalise, $nonce, $this->cle);

        return base64_encode($nonce . $chiffre);
    }

    public function dechiffrer(string $ibanChiffre): string
    {
        $binaire = base64_decode($ibanChiffre, true);
        if ($binaire === false || \strlen($binaire) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('IBAN chiffré invalide.');
        }

        $nonce = substr($binaire, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = substr($binaire, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $clair = sodium_crypto_secretbox_open($chiffre, $nonce, $this->cle);
        if ($clair === false) {
            throw new \RuntimeException('Déchiffrement IBAN impossible (clé ou valeur invalide).');
        }

        return $clair;
    }
}
