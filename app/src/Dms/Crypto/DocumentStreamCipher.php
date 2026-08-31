<?php

declare(strict_types=1);

namespace App\Dms\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement/déchiffrement **en flux** du contenu binaire des documents (RG-DMS-25/28, plan-dms.md
 * §0.2) — `crypto_secretstream_xchacha20poly1305` (AEAD, détecte troncature/altération via
 * `TAG_FINAL`), traitement par blocs de 1 MiB : jamais le fichier entier en mémoire, quel que soit son
 * poids (le conteneur PHP est plafonné à 512 Mo, `docker/php/conf.d/zz-memory.ini`).
 *
 * **Ne réutilise pas** `ChiffreurApiKeyOcr`/`ChiffreurIban` (`sodium_crypto_secretbox`, une seule
 * opération mémoire, adaptés à de courtes chaînes) : un fichier peut peser plusieurs dizaines de Mo.
 *
 * **Échec fermé strict** (RG-DMS-25) : contrairement à `ChiffreurIban`, aucune dérivation de
 * convenance si `DMS_ENCRYPTION_KEY` est absente ou mal formée — le conteneur refuse de démarrer dès la
 * construction (CA-12). La mesure protège d'un disque ou d'une sauvegarde exfiltrés, **pas** d'une
 * application compromise qui détiendrait la clé.
 */
final class DocumentStreamCipher
{
    private const TAILLE_BLOC_CLAIR = 1_048_576; // 1 MiB — mémoire bornée quel que soit le poids du fichier.

    private readonly string $key;

    public function __construct(
        #[Autowire(env: 'DMS_ENCRYPTION_KEY')] string $keyBase64,
    ) {
        $key = base64_decode($keyBase64, true);
        if ($key === false || \strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new \RuntimeException(
                'dms.error.encryption_key_invalid : DMS_ENCRYPTION_KEY absente ou invalide (32 octets '
                . 'base64 requis) — démarrage refusé (RG-DMS-25).',
            );
        }
        $this->key = $key;
    }

    /**
     * Lit `$source` (ressource) par blocs, écrit le flux chiffré dans `$destination` (ressource).
     * Retourne le sha256 hex du contenu **en clair**, calculé en une seule passe pendant le chiffrement
     * (RG-DMS-17) — pas de second passage sur le fichier.
     *
     * @param resource $source
     * @param resource $destination
     */
    public function encrypt($source, $destination): string
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
        fwrite($destination, $header); // 24 octets.

        $hash = hash_init('sha256');
        while (!feof($source)) {
            $bloc = fread($source, self::TAILLE_BLOC_CLAIR);
            if ($bloc === false) {
                throw new \RuntimeException('dms.error.stream_read_failed');
            }
            hash_update($hash, $bloc);
            $dernier = feof($source);
            $tag = $dernier
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            fwrite($destination, sodium_crypto_secretstream_xchacha20poly1305_push($state, $bloc, '', $tag));
        }

        return hash_final($hash);
    }

    /**
     * Lit `$source` (ressource, format produit par `encrypt()`) par blocs, écrit le contenu **en
     * clair** dans `$destination` (ex. `php://output`). Lève si le flux est tronqué ou altéré (échec
     * d'authentification AEAD) — jamais de déchiffrement silencieusement erroné.
     *
     * @param resource $source
     * @param resource $destination
     */
    public function decrypt($source, $destination): void
    {
        $header = fread($source, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if ($header === false || \strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new \RuntimeException('dms.error.encrypted_stream_header_invalid');
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
        $tailleBlocChiffre = self::TAILLE_BLOC_CLAIR + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        while (!feof($source)) {
            $bloc = fread($source, $tailleBlocChiffre);
            if ($bloc === false || $bloc === '') {
                break;
            }
            $resultat = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $bloc);
            if ($resultat === false) {
                throw new \RuntimeException('dms.error.encrypted_stream_tampered : échec d\'authentification AEAD.');
            }
            [$clair, $tag] = $resultat;
            fwrite($destination, $clair);
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                break;
            }
        }
    }
}
