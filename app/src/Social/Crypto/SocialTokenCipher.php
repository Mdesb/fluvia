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
 * **Le format est posé maintenant, le mécanisme viendra ensuite**, et cette séparation est délibérée :
 * le format doit précéder le premier jeton écrit, sinon il coûte une reprise de données ; le mécanisme
 * — rechiffrer, basculer, purger l'ancienne clé — peut arriver n'importe quand. Tant qu'une seule clé
 * est déclarée, le comportement est identique à celui d'avant.
 *
 * **Un préfixe plutôt qu'une colonne à côté** : la version voyage avec la valeur. Une colonne séparée
 * peut être mise à jour sans l'autre — et une version qui ment sur la clé employée est pire que pas de
 * version du tout, puisqu'elle fait choisir la mauvaise clé en silence. Le préfixe ne coûte par
 * ailleurs aucune migration, les colonnes étant du texte.
 *
 * Une valeur **sans préfixe** est lue comme la version 1 : les lignes écrites par la première mouture
 * de SOC-1, déjà fusionnée, restent lisibles sans reprise.
 *
 * Ce que ce coffre ne fait pas, et c'est délibéré : il ne journalise rien. Un jeton ne sort jamais
 * d'ici — ni dans une réponse d'API, ni dans un événement, ni dans un journal.
 */
final class SocialTokenCipher
{
    /**
     * Version de la clé employée pour **chiffrer**. Les versions antérieures restent déchiffrables si
     * leur clé est encore déclarée dans `$keysByVersion`.
     *
     * Faire de la bascule une modification de code plutôt qu'un réglage d'environnement est voulu :
     * une rotation de clé est un acte délibéré, qui se relit et se date, pas un interrupteur qu'on
     * pousse par erreur en éditant un fichier de configuration.
     */
    private const CURRENT_KEY_VERSION = 1;

    /** @var array<int, ChiffreurSecret> */
    private readonly array $keysByVersion;

    public function __construct(
        #[Autowire(env: 'SOCIAL_TOKEN_ENCRYPTION_KEY')]
        string $keyBase64,
    ) {
        // À la rotation : la nouvelle clé prend la version courante, l'ancienne reste ici en
        // déchiffrement seul jusqu'à ce que le compteur de lignes à l'ancienne version tombe à zéro.
        $this->keysByVersion = [
            self::CURRENT_KEY_VERSION => new ChiffreurSecret($keyBase64),
        ];
    }

    public function encrypt(string $plain): string
    {
        $cipher = $this->keysByVersion[self::CURRENT_KEY_VERSION];

        return sprintf('v%d:%s', self::CURRENT_KEY_VERSION, $cipher->chiffrer($plain));
    }

    public function decrypt(string $value): string
    {
        [$version, $payload] = $this->split($value);

        $cipher = $this->keysByVersion[$version] ?? null;
        if ($cipher === null) {
            // Échec explicite, jamais un essai avec la clé courante : réussir par accident ferait
            // croire la rotation terminée, et échouer silencieusement ferait perdre le jeton sans que
            // personne ne sache lequel. On dit quelle version manque, jamais la valeur.
            throw new SocialTokenCipherException(sprintf('Aucune clé déclarée pour la version %d du coffre social.', $version));
        }

        return $cipher->dechiffrer($payload);
    }

    /**
     * Version de clé portée par une valeur stockée.
     *
     * Exposée pour que la rotation à venir puisse compter les lignes restant à rechiffrer et afficher
     * la répartition **avant** qu'on ne retire une clé de l'environnement — vérifier après coup
     * reviendrait à découvrir la perte au premier envoi.
     */
    public function keyVersionOf(string $value): int
    {
        return $this->split($value)[0];
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
