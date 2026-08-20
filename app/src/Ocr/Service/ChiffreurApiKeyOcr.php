<?php

declare(strict_types=1);

namespace App\Ocr\Service;

use App\Securite\Crypto\ChiffreurSecret;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement/déchiffrement réversible de la clé API du fournisseur OCR (RG-OCR-06) — **réutilise**
 * `App\Securite\Crypto\ChiffreurSecret` (patron libsodium `crypto_secretbox` déjà posé pour le secret
 * MFA / l'IBAN SEPA), avec une **clé dédiée par usage** (invariant noyau commun #4) : pas un troisième
 * mécanisme de chiffrement.
 *
 * La clé est injectée depuis `OCR_API_KEY_ENCRYPTION_KEY` (ajoutée par l'intégrateur dans `.env`, au
 * même titre que `MFA_ENCRYPTION_KEY` / `SEPA_IBAN_KEY`). **Aucun repli codé en dur** : si la variable
 * est absente, le conteneur refuse de démarrer — échec fermé (D3), jamais de chiffrement de façade.
 */
final class ChiffreurApiKeyOcr
{
    private readonly ChiffreurSecret $chiffreurSecret;

    public function __construct(
        #[Autowire(env: 'OCR_API_KEY_ENCRYPTION_KEY')]
        string $cleBase64,
    ) {
        $this->chiffreurSecret = new ChiffreurSecret($cleBase64);
    }

    public function chiffrer(string $clair): string
    {
        return $this->chiffreurSecret->chiffrer($clair);
    }

    public function dechiffrer(string $valeur): string
    {
        return $this->chiffreurSecret->dechiffrer($valeur);
    }
}
