<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * TROUSSEAU DE CLÉS DE SCELLEMENT NF525 (#361) — une clé ACTIVE qui signe, des clés RETIRÉES qui ne
 * servent qu'à vérifier.
 *
 * ── POURQUOI ─────────────────────────────────────────────────────────────────────────────────────
 *
 * La signature d'un maillon est un HMAC : symétrique, donc il n'existe pas de « clé publique » à
 * garder. Vérifier un maillon ancien exige la clé SECRÈTE qui l'a signé. Or la conservation des
 * données d'encaissement est de six ans (BOI-TVA-DECLA-30-10-30 §155) et l'intégrité doit se prouver
 * pendant toute cette durée (§220, §250). En préprod, la clé a changé deux fois sur une chaîne
 * existante : 14 maillons sont devenus invérifiables, et rien ne l'a refusé.
 *
 * ── CE QUE FAIT CE TROUSSEAU ─────────────────────────────────────────────────────────────────────
 *
 *  1. chaque maillon porte l'IDENTIFIANT de la clé qui l'a signé (`keyIdOf`) : une empreinte HMAC non
 *     réversible, jamais la clé ;
 *  2. la vérification choisit la clé par cet identifiant ; un identifiant inconnu est dit « clé
 *     inconnue du trousseau », pas « signature invalide » — ce ne sont pas les mêmes causes ;
 *  3. un maillon ANCIEN, sans identifiant, est vérifié avec la clé active, puis avec les clés
 *     retirées (compatibilité : on ne sait pas laquelle l'a signé) ;
 *  4. ÉCHEC FERMÉ (`assertCanExtend`) : on ne signe jamais par-dessus une chaîne dont le dernier
 *     maillon ne se vérifie plus. Une rotation sans trousseau est refusée au premier scellement ;
 *  5. ORDRE DES CLÉS (#369, `assertCanExtendInOrder`, `regressionProblem`) : une clé qu'une chaîne a
 *     quittée n'y revient jamais, une clé retirée restant sinon valable sans limite. Rotation = clé
 *     NEUVE. La règle elle-même vit dans `SealKeyOrder` / `SealKeyOrderTracker`.
 *
 * Les clés retirées viennent de `NF525_*_RETIRED_KEYS` (liste séparée par des virgules ou des
 * espaces). Leur sauvegarde hors du serveur est décrite dans `infra/README.md`.
 */
final class SealKeyring
{
    /** Libellé fixe : l'identifiant ne doit rien révéler de la clé et ne peut servir à rien d'autre. */
    private const KEY_ID_LABEL = 'fluvia-nf525-seal-key-id';

    private readonly string $activeKeyId;

    /** @var array<string, string> identifiant => clé retirée */
    private array $retired = [];

    public function __construct(
        // Nom de la variable d'environnement de la clé active : il nomme la chaîne dans les messages
        // et dit à l'exploitant quelle variable restaurer.
        private readonly string $variable,
        #[\SensitiveParameter]
        private readonly string $activeKey,
        #[\SensitiveParameter]
        ?string $retiredKeys = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if (trim($activeKey) === '') {
            throw new \InvalidArgumentException(sprintf('Trousseau NF525 %s : la clé active est vide.', $variable));
        }
        $this->activeKeyId = self::keyIdOf($activeKey);

        foreach (preg_split('/[\s,]+/', (string) $retiredKeys, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $key) {
            $id = self::keyIdOf($key);
            if ($id !== $this->activeKeyId) {
                $this->retired[$id] = $key;
            }
        }
    }

    /** Empreinte non réversible d'une clé : 16 caractères hexadécimaux, jamais la clé. */
    public static function keyIdOf(#[\SensitiveParameter] string $key): string
    {
        return substr(hash_hmac('sha256', self::KEY_ID_LABEL, $key), 0, 16);
    }

    public function variable(): string
    {
        return $this->variable;
    }

    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }

    /** @return list<string> identifiants des clés retirées (jamais les clés) */
    public function retiredKeyIds(): array
    {
        return array_keys($this->retired);
    }

    /**
     * Signe un MAILLON avec la clé ACTIVE. Seule la clé active signe.
     *
     * ⚠ L'IDENTIFIANT EST SIGNÉ AVEC L'EMPREINTE (`HMAC(empreinte|identifiant)`). Sans cela, qui peut
     * écrire en base changerait `seal_key_id` sans être vu : l'effacer élargirait la vérification à
     * toutes les clés (chemin des maillons anciens), le remplacer ferait passer une altération pour
     * une clé manquante. Lié à la signature, tout changement de l'identifiant la rend invalide.
     */
    public function sign(string $fingerprint): string
    {
        return hash_hmac('sha256', self::boundMessage($fingerprint, $this->activeKeyId), $this->activeKey);
    }

    /**
     * Signe une donnée qui ne porte PAS d'identifiant de clé (l'archive fiscale) : vérifiée ensuite
     * par `check(…, null)`, avec la clé active puis les clés retirées.
     */
    public function signWithoutId(string $fingerprint): string
    {
        return hash_hmac('sha256', $fingerprint, $this->activeKey);
    }

    /**
     * Vérifie une signature avec la clé désignée par `$keyId`. Sans identifiant (maillon antérieur au
     * trousseau, signé sans identifiant) : clé active, puis chaque clé retirée.
     */
    public function check(string $fingerprint, string $signature, ?string $keyId): SealCheck
    {
        if ($keyId === null || $keyId === '') {
            foreach ([$this->activeKey, ...array_values($this->retired)] as $key) {
                if (hash_equals(hash_hmac('sha256', $fingerprint, $key), $signature)) {
                    return SealCheck::Valid;
                }
            }

            return SealCheck::InvalidSignature;
        }

        $key = $keyId === $this->activeKeyId ? $this->activeKey : ($this->retired[$keyId] ?? null);
        if ($key === null) {
            return SealCheck::UnknownKey;
        }

        return hash_equals(hash_hmac('sha256', self::boundMessage($fingerprint, $keyId), $key), $signature)
            ? SealCheck::Valid
            : SealCheck::InvalidSignature;
    }

    private static function boundMessage(string $fingerprint, string $keyId): string
    {
        return $fingerprint . '|' . $keyId;
    }

    /** Libellé d'anomalie pour un rapport de vérification (`null` si le maillon est valide). */
    public function problem(SealCheck $check, ?string $keyId): ?string
    {
        return match ($check) {
            SealCheck::Valid => null,
            SealCheck::InvalidSignature => 'signature invalide',
            SealCheck::UnknownKey => sprintf(
                'clé inconnue du trousseau : ce maillon désigne la clé d\'identifiant %s, que le '
                . 'trousseau %s ne contient pas. Il ne peut donc pas être vérifié : soit cette clé manque '
                . 'au trousseau (restaurez-la en clé retirée, %s), soit l\'identifiant a été modifié. '
                . 'Tant qu\'il n\'est pas vérifié, ce maillon ne prouve rien.',
                self::safeId($keyId),
                $this->variable,
                $this->retiredVariable(),
            ),
        };
    }

    /**
     * ÉCHEC FERMÉ : refuse de prolonger une chaîne dont le dernier maillon ne se vérifie pas avec le
     * trousseau. Signer par-dessus produirait une chaîne dont plus rien ne prouve le début.
     *
     * @throws SealingRefusedException
     */
    public function assertCanExtend(string $lastFingerprint, string $lastSignature, ?string $lastKeyId, string $chainLabel): void
    {
        $check = $this->check($lastFingerprint, $lastSignature, $lastKeyId);
        if ($check === SealCheck::Valid) {
            return;
        }

        $cause = $check === SealCheck::UnknownKey
            ? sprintf('il a été signé par la clé d\'identifiant %s, absente du trousseau', self::safeId($lastKeyId))
            : 'sa signature ne se vérifie avec aucune clé du trousseau';

        // L'ALERTE. Niveau critique : c'est une chaîne fiscale qui ne peut plus être prolongée.
        // On journalise les IDENTIFIANTS, jamais les clés.
        $this->logger->critical('NF525 : scellement refusé, le dernier maillon de la chaîne ne se vérifie plus avec le trousseau.', [
            'variable' => $this->variable,
            'chaine' => $chainLabel,
            'cause' => $check->value,
            'cle_du_maillon' => self::safeId($lastKeyId),
            'cle_active' => $this->activeKeyId,
            'cles_retirees' => $this->retiredKeyIds(),
        ]);

        throw new SealingRefusedException(sprintf(
            'Scellement refusé (NF525, %s) : le dernier maillon de la chaîne ne se vérifie pas — %s. '
            . 'Signer par-dessus rendrait la chaîne invérifiable. La clé de scellement %s a probablement '
            . 'changé : restaurez la clé qui a signé ce maillon, ou ajoutez-la au trousseau des clés '
            . 'retirées (%s). Procédure : infra/README.md, « Trousseau NF525 ».',
            $chainLabel,
            $cause,
            $this->variable,
            $this->retiredVariable(),
        ));
    }

    /**
     * Les identifiants des maillons viennent de la base, donc d'un attaquant possible : on n'affiche tel
     * quel qu'un identifiant au format produit par `keyIdOf` (16 hexadécimaux) ; tout autre est décrit
     * (longueur, empreinte tronquée), jamais recopié dans un message, une anomalie ou un journal.
     */
    public static function safeId(?string $keyId): string
    {
        if ($keyId === null) {
            return 'sans identifiant';
        }
        if (preg_match('/^[0-9a-f]{16}$/D', $keyId) === 1) {
            return $keyId;
        }

        return sprintf('identifiant non conforme (longueur %d, sha256 tronqué %s)', strlen($keyId), substr(hash('sha256', $keyId), 0, 8));
    }

    /** Libellé d'anomalie d'une régression de clé (#369) : identifiants seulement, jamais les clés. */
    public function regressionProblem(KeyRegression $regression): string
    {
        if ($regression->kind === KeyRegression::NULL_AFTER_KEY) {
            return sprintf(
                'régression de clé : le maillon n° %d ne porte aucun identifiant de clé, alors qu\'un maillon '
                . 'précédent de la chaîne était signé par la clé d\'identifiant %s. Une chaîne ne revient jamais '
                . 'à l\'absence d\'identifiant : maillon forgé ou rotation incorrecte.',
                $regression->sequence,
                self::safeId($regression->previousKeyId),
            );
        }

        return sprintf(
            'régression de clé : le maillon n° %d porte la clé d\'identifiant %s, que cette chaîne avait déjà '
            . 'quittée (%s). Une clé quittée ne revient jamais dans une chaîne : maillon forgé ou rotation '
            . 'incorrecte.',
            $regression->sequence,
            self::safeId($regression->keyId),
            $regression->previousKeyId === null
                ? 'elle était passée à des maillons sans identifiant'
                : sprintf('elle était passée à la clé d\'identifiant %s', self::safeId($regression->previousKeyId)),
        );
    }

    /**
     * ÉCHEC FERMÉ SUR L'ORDRE DES CLÉS (#369) : refuse de prolonger une chaîne qui a déjà quitté la clé
     * active, ou dont le dernier maillon est lui-même en régression. À appeler APRÈS `assertCanExtend`
     * (qui juge la signature du dernier maillon) ; les `KeyRun` viennent d'une requête d'index, pas d'une
     * relecture de la chaîne.
     *
     * @param list<KeyRun> $runs
     *
     * @throws SealingRefusedException
     */
    public function assertCanExtendInOrder(array $runs, string $chainLabel): void
    {
        try {
            $block = SealKeyOrder::extensionBlock($runs, $this->activeKeyId);
        } catch (\InvalidArgumentException) {
            $this->refuseUnreadableOrder($chainLabel);
        }
        if ($block === null) {
            return;
        }

        // L'ALERTE : identifiants seulement, jamais les clés.
        $this->logger->critical('NF525 : scellement refusé, la chaîne a quitté la clé de scellement ou son dernier maillon est en régression de clé.', [
            'variable' => $this->variable,
            'chaine' => $chainLabel,
            'cause' => $block['reason'],
            'cle_du_maillon' => $block['keyId'] === null ? null : self::safeId($block['keyId']),
            'cle_autre' => $block['otherKeyId'] === null ? null : self::safeId($block['otherKeyId']),
            'cle_active' => $this->activeKeyId,
            'cles_retirees' => $this->retiredKeyIds(),
        ]);

        if ($block['reason'] === SealKeyOrder::BLOCK_ACTIVE_KEY_LEFT) {
            throw new SealingRefusedException(sprintf(
                'Scellement refusé (NF525, %s) : la chaîne a déjà quitté la clé de scellement active (identifiant %s, '
                . 'variable %s) ; sceller avec elle ferait revenir la chaîne en arrière. Ne remettez pas une ancienne '
                . 'clé : passez à une clé NEUVE (nouvelle valeur de %s, l\'ancienne active ajoutée à %s). '
                . 'Procédure : infra/README.md, « Trousseau NF525 ».',
                $chainLabel,
                $this->activeKeyId,
                $this->variable,
                $this->variable,
                $this->retiredVariable(),
            ));
        }

        // Dernier maillon en régression : changer de clé n'y change rien, c'est la chaîne qu'il faut examiner.
        throw new SealingRefusedException(sprintf(
            'Scellement refusé (NF525, %s) : le dernier maillon est lui-même en régression de clé (%s). Vérifiez la '
            . 'chaîne (…/verifier-chaine) et consultez l\'alerte critique du journal : un maillon a été forgé ou une '
            . 'rotation a été faite à l\'envers. Procédure : infra/README.md, « Trousseau NF525 ».',
            $chainLabel,
            $block['keyId'] === null
                ? sprintf('maillon sans identifiant après la clé %s', self::safeId($block['otherKeyId']))
                : sprintf('clé %s réapparue après la clé %s', self::safeId($block['keyId']), self::safeId($block['otherKeyId'])),
        ));
    }

    /**
     * Comme `assertCanExtendInOrder`, mais LIT les intervalles lui-même (`$loadRuns`) : toute entrée
     * inexploitable (séquence forgée à 0, identifiant de chaîne nul ou illisible, intervalles incohérents)
     * devient un refus de sceller (409 + alerte), jamais une erreur 500 qui laisserait l'état ambigu.
     *
     * @param \Closure(): list<KeyRun> $loadRuns
     *
     * @throws SealingRefusedException
     */
    public function assertCanExtendInOrderFrom(\Closure $loadRuns, string $chainLabel): void
    {
        try {
            $runs = $loadRuns();
        } catch (\InvalidArgumentException) {
            $this->refuseUnreadableOrder($chainLabel);
        }
        $this->assertCanExtendInOrder($runs, $chainLabel);
    }

    /** L'ordre des clés n'a pas pu être établi : échec fermé. Ni message ni valeur d'entrée recopiés. */
    private function refuseUnreadableOrder(string $chainLabel): never
    {
        $this->logger->critical('NF525 : scellement refusé, l\'ordre des clés de la chaîne n\'a pas pu être établi (données de chaîne inexploitables).', [
            'variable' => $this->variable,
            'chaine' => $chainLabel,
            'cause' => 'unreadable_order',
            'cle_active' => $this->activeKeyId,
            'cles_retirees' => $this->retiredKeyIds(),
        ]);

        throw new SealingRefusedException(sprintf(
            'Scellement refusé (NF525, %s) : l\'ordre des clés de la chaîne n\'a pas pu être établi (maillon de séquence '
            . 'impossible ou chaîne illisible). Signer par-dessus pourrait aggraver la chaîne : vérifiez-la '
            . '(…/verifier-chaine) et consultez l\'alerte critique du journal. Procédure : infra/README.md, « Trousseau NF525 ».',
            $chainLabel,
        ));
    }

    /** `NF525_SEAL_KEY` → `NF525_SEAL_RETIRED_KEYS` : la variable qui porte les clés retirées. */
    public function retiredVariable(): string
    {
        return (string) preg_replace('/_KEY$/', '_RETIRED_KEYS', $this->variable);
    }
}
