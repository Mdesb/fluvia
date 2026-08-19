<?php

declare(strict_types=1);

namespace App\Ocr\Service;

use App\Securite\Crypto\ChiffreurSecret;

/**
 * Chiffrement/déchiffrement réversible de la clé API du fournisseur OCR (RG-OCR-06) — **réutilise**
 * `App\Securite\Crypto\ChiffreurSecret` (patron libsodium `crypto_secretbox` déjà posé pour le secret
 * MFA/l'IBAN SEPA), avec une clé dédiée par usage (invariant noyau commun #4) : pas un troisième
 * mécanisme de chiffrement.
 *
 * ⚠ Écart d'implémentation assumé (mission FIN-0 : périmètre restreint à `app/src/Ocr/**`) —
 * plan-ocr.md §0.4 propose une **2ᵉ définition de service en YAML**
 * (`class: ChiffreurSecret, arguments: ['%env(OCR_API_KEY_ENCRYPTION_KEY)%']`) plus l'ajout de la
 * variable dans `.env`/`.env.test`. `config/services.yaml` et `.env*` sont **hors périmètre** de cet
 * agent (fichiers partagés/intégrateur, plusieurs agents sur le dépôt). Cette classe réutilise donc
 * `ChiffreurSecret` **par composition** (même algorithme, aucune duplication de code) et résout
 * `OCR_API_KEY_ENCRYPTION_KEY` elle-même (process env / `.env*` si déjà chargé, repli déterministe
 * sinon) sans dépendre d'une déclaration préalable dans un fichier hors périmètre — reste autonome et
 * testable. **À signaler à l'intégrateur** : basculer vers le patron YAML du plan dès que
 * `OCR_API_KEY_ENCRYPTION_KEY` est ajoutée à `.env`/`.env.test` (valeur secrète dédiée en production,
 * au même titre que `MFA_ENCRYPTION_KEY`/`SEPA_IBAN_KEY`) — comportement inchangé pour l'appelant.
 */
final class ChiffreurApiKeyOcr
{
    private const ENV_VAR = 'OCR_API_KEY_ENCRYPTION_KEY';

    private readonly ChiffreurSecret $chiffreurSecret;

    public function __construct(?string $cleBase64 = null)
    {
        $this->chiffreurSecret = new ChiffreurSecret($cleBase64 ?? $this->resoudreCleEnvironnement());
    }

    public function chiffrer(string $clair): string
    {
        return $this->chiffreurSecret->chiffrer($clair);
    }

    public function dechiffrer(string $valeur): string
    {
        return $this->chiffreurSecret->dechiffrer($valeur);
    }

    private function resoudreCleEnvironnement(): string
    {
        $valeur = $_ENV[self::ENV_VAR] ?? $_SERVER[self::ENV_VAR] ?? getenv(self::ENV_VAR);
        if (\is_string($valeur) && trim($valeur) !== '') {
            return $valeur;
        }

        // Valeur de repli déterministe (dev/test uniquement) : `ChiffreurSecret` dérive de toute façon
        // une clé de 32 octets valide à partir de cette chaîne (même patron que `MFA_ENCRYPTION_KEY`
        // non conforme base64). En production, `OCR_API_KEY_ENCRYPTION_KEY` doit être définie dans
        // l'environnement réel (secret/vault) — cf. écart documenté ci-dessus.
        return 'ocr-api-key-encryption-key-dev-fallback';
    }
}
