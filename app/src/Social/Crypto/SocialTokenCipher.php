<?php

declare(strict_types=1);

namespace App\Social\Crypto;

use App\Securite\Crypto\ChiffreurSecret;
use App\Social\Exception\SocialTokenCipherException;
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
 * ## La valeur stockée porte l'identifiant de la clé qui l'a chiffrée
 *
 * Format : `v<version>:<base64(nonce||chiffré)>`.
 *
 * Sans cet identifiant, changer la clé rendrait tous les jetons indéchiffrables et obligerait chaque
 * établissement à repasser le parcours d'autorisation de chaque réseau. Personne ne ferait jamais
 * cela — donc la clé ne changerait jamais, et une clé qu'on ne peut pas changer est une clé qu'on ne
 * peut pas révoquer le jour où elle fuit. C'est exactement ce qu'on cherche à éviter en chiffrant.
 *
 * **Un préfixe plutôt qu'une colonne à côté** : la version voyage avec la valeur. Une colonne séparée
 * peut être mise à jour sans l'autre — et une version qui ment sur la clé employée est pire que pas de
 * version du tout, puisqu'elle fait choisir la mauvaise clé en silence. Le préfixe ne coûte par
 * ailleurs aucune migration, les colonnes étant du texte.
 *
 * Une valeur **sans préfixe** est lue comme la version 1 : les lignes écrites par la première mouture
 * de SOC-1 restent lisibles sans reprise.
 *
 * ## Rotation : une clé active, N clés retirées en déchiffrement seul
 *
 * `SOCIAL_TOKEN_ENCRYPTION_KEYS_RETIRED` porte les générations précédentes, au format
 * `1:<base64>,2:<base64>`. Elles ne servent **jamais** à chiffrer : elles permettent de lire ce qui
 * n'a pas encore été rechiffré, le temps que `social:rotate-token-key` fasse son travail. La variable
 * est facultative — tant qu'aucune rotation n'a eu lieu, il n'y a rien à y mettre, et exiger une
 * variable vide serait une cérémonie sans contenu.
 *
 * **La bascule de version se fait dans le code, pas dans l'environnement.** Une rotation de clé est un
 * acte délibéré qui se relit, se date et se raconte dans un message de commit ; un interrupteur
 * d'environnement se pousse par erreur en éditant un fichier à trois heures du matin.
 *
 * Ce que ce coffre ne fait pas, et c'est délibéré : il ne journalise rien. Un jeton ne sort jamais
 * d'ici — ni dans une réponse d'API, ni dans un événement, ni dans un journal.
 */
final class SocialTokenCipher
{
    /**
     * Version de la clé employée pour **chiffrer**.
     *
     * À la rotation : on incrémente cette constante, on met la nouvelle clé dans
     * `SOCIAL_TOKEN_ENCRYPTION_KEY`, et on déplace l'ancienne dans
     * `SOCIAL_TOKEN_ENCRYPTION_KEYS_RETIRED` sous son ancien numéro.
     */
    public const CURRENT_KEY_VERSION = 1;

    /** @var array<int, ChiffreurSecret> */
    private readonly array $keysByVersion;

    private readonly int $currentVersion;

    /**
     * @param int $currentVersion Version active. Paramètre plutôt que lecture directe de la constante
     *                            pour que la rotation soit **éprouvable** : sans cela, aucun test ne
     *                            pourrait vérifier qu'une valeur de génération précédente reste
     *                            lisible, et on découvrirait le défaut le jour de la rotation, sur des
     *                            jetons réels. L'autowiring ne l'injecte pas — en production, c'est
     *                            toujours la constante.
     */
    public function __construct(
        #[Autowire(env: 'SOCIAL_TOKEN_ENCRYPTION_KEY')]
        string $keyBase64,
        #[Autowire(env: 'default::SOCIAL_TOKEN_ENCRYPTION_KEYS_RETIRED')]
        ?string $retiredKeysBase64 = null,
        int $currentVersion = self::CURRENT_KEY_VERSION,
    ) {
        $keys = [$currentVersion => new ChiffreurSecret($keyBase64)];

        foreach ($this->parseRetired($retiredKeysBase64) as $version => $material) {
            if ($version === $currentVersion) {
                // Une clé retirée qui revendique la version active ferait déchiffrer avec l'une et
                // chiffrer avec l'autre, sans qu'aucune erreur ne se produise avant que les jetons ne
                // soient devenus illisibles. On refuse de démarrer plutôt que de le découvrir ainsi.
                throw new SocialTokenCipherException(sprintf(
                    'La version %d est déclarée à la fois active et retirée dans la configuration du coffre social.',
                    $version,
                ));
            }
            $keys[$version] = new ChiffreurSecret($material);
        }

        $this->keysByVersion = $keys;
        $this->currentVersion = $currentVersion;
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    /** @return list<int> Versions déchiffrables, la plus récente d'abord. */
    public function knownVersions(): array
    {
        $versions = array_keys($this->keysByVersion);
        rsort($versions);

        return $versions;
    }

    public function encrypt(string $plain): string
    {
        return sprintf('v%d:%s', $this->currentVersion, $this->keysByVersion[$this->currentVersion]->chiffrer($plain));
    }

    public function decrypt(string $value): string
    {
        [$version, $payload] = $this->split($value);

        $cipher = $this->keysByVersion[$version] ?? null;
        if ($cipher === null) {
            // Échec explicite, jamais un essai avec la clé courante : réussir par accident ferait
            // croire la rotation terminée, et échouer silencieusement ferait perdre le jeton sans que
            // personne ne sache lequel. On dit quelle version manque, jamais la valeur.
            throw new SocialTokenCipherException(sprintf(
                'Aucune clé déclarée pour la version %d du coffre social — a-t-elle été retirée avant la fin de la rotation ?',
                $version,
            ));
        }

        return $cipher->dechiffrer($payload);
    }

    /**
     * Rechiffre une valeur avec la clé active. Rend `null` si elle y est déjà — ce qui rend la
     * rotation idempotente et donc reprenable : on peut relancer la commande autant de fois qu'on
     * veut, elle ne retouche que ce qui reste.
     */
    public function reencrypt(string $value): ?string
    {
        if ($this->keyVersionOf($value) === $this->currentVersion) {
            return null;
        }

        return $this->encrypt($this->decrypt($value));
    }

    /**
     * Version de clé portée par une valeur stockée.
     *
     * Exposée pour que la rotation puisse compter les lignes restant à rechiffrer et afficher la
     * répartition **avant** qu'on ne retire une clé de l'environnement — vérifier après coup
     * reviendrait à découvrir la perte au premier envoi.
     */
    public function keyVersionOf(string $value): int
    {
        return $this->split($value)[0];
    }

    /**
     * Motif SQL des valeurs déjà à la version active, pour que la commande de rotation ne charge pas
     * en mémoire ce qu'elle n'a pas à toucher.
     */
    public function currentVersionPrefix(): string
    {
        return sprintf('v%d:', $this->currentVersion);
    }

    /**
     * @return array<int, string>
     */
    private function parseRetired(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $keys = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $separator = strpos($entry, ':');
            if ($separator === false || $separator === 0) {
                throw new SocialTokenCipherException('Clé retirée mal formée : attendu « <version>:<base64> ».');
            }

            $version = substr($entry, 0, $separator);
            if (!ctype_digit($version)) {
                throw new SocialTokenCipherException('Clé retirée mal formée : la version doit être un entier.');
            }

            $keys[(int) $version] = substr($entry, $separator + 1);
        }

        return $keys;
    }

    /**
     * @return array{0: int, 1: string} version, charge utile base64
     */
    private function split(string $value): array
    {
        if (preg_match('/^v(\d+):(.*)$/s', $value, $matches) === 1) {
            return [(int) $matches[1], $matches[2]];
        }

        // Valeur antérieure au format versionné : c'est la version 1 par construction, puisque aucune
        // rotation n'a pu avoir lieu avant que les versions n'existent.
        return [1, $value];
    }
}
