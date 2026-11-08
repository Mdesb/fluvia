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
 *     maillon ne se vérifie plus. Une rotation sans trousseau est refusée au premier scellement.
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

    /** Signe une empreinte avec la clé ACTIVE. Seule la clé active signe. */
    public function sign(string $fingerprint): string
    {
        return hash_hmac('sha256', $fingerprint, $this->activeKey);
    }

    /**
     * Vérifie une signature avec la clé désignée par `$keyId`. Sans identifiant (maillon antérieur au
     * trousseau) : clé active, puis chaque clé retirée.
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

        return hash_equals(hash_hmac('sha256', $fingerprint, $key), $signature)
            ? SealCheck::Valid
            : SealCheck::InvalidSignature;
    }

    /** Libellé d'anomalie pour un rapport de vérification (`null` si le maillon est valide). */
    public function problem(SealCheck $check, ?string $keyId): ?string
    {
        return match ($check) {
            SealCheck::Valid => null,
            SealCheck::InvalidSignature => 'signature invalide',
            SealCheck::UnknownKey => sprintf(
                'clé inconnue du trousseau : ce maillon a été signé par la clé d\'identifiant %s, que le '
                . 'trousseau %s ne contient pas. Ce n\'est pas une preuve d\'altération : restaurez cette '
                . 'clé en clé retirée (%s) pour le vérifier.',
                (string) $keyId,
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
            ? sprintf('il a été signé par la clé d\'identifiant %s, absente du trousseau', (string) $lastKeyId)
            : 'sa signature ne se vérifie avec aucune clé du trousseau';

        // L'ALERTE. Niveau critique : c'est une chaîne fiscale qui ne peut plus être prolongée.
        // On journalise les IDENTIFIANTS, jamais les clés.
        $this->logger->critical('NF525 : scellement refusé, le dernier maillon de la chaîne ne se vérifie plus avec le trousseau.', [
            'variable' => $this->variable,
            'chaine' => $chainLabel,
            'cause' => $check->value,
            'cle_du_maillon' => $lastKeyId,
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

    /** `NF525_SEAL_KEY` → `NF525_SEAL_RETIRED_KEYS` : la variable qui porte les clés retirées. */
    public function retiredVariable(): string
    {
        return (string) preg_replace('/_KEY$/', '_RETIRED_KEYS', $this->variable);
    }
}
