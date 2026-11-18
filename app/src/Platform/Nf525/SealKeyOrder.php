<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

/**
 * RÈGLE D'ORDRE DES CLÉS (#369), FORME INTERVALLES : même définition que `SealKeyOrderTracker`, calculée
 * à partir des `KeyRun` d'une chaîne (début et fin de chaque clé), sans relire les maillons. Sert au refus
 * de sceller et à `app:nf525:check-keys`.
 *
 * Deux clés distinctes dont les intervalles se chevauchent s'entrelacent forcément (les séquences d'une
 * chaîne sont distinctes) ; des intervalles disjoints ne s'entrelacent pas. NULL doit venir en premier :
 * un intervalle NULL qui finit après le début d'une clé est une régression.
 *
 * ÉCHEC FERMÉ sur l'entrée : la liste doit être une vraie liste de `KeyRun`, avec UN seul intervalle par
 * identifiant (NULL compte pour un identifiant) ; sinon `\InvalidArgumentException`, jamais un verdict.
 */
final class SealKeyOrder
{
    public const BLOCK_ACTIVE_KEY_LEFT = 'active_key_left';
    public const BLOCK_LAST_LINK_REGRESSED = 'last_link_regressed';

    /**
     * Couples de clés entrelacées (identifiants seulement, `null` = sans identifiant). C'est un TÉMOIN, pas
     * l'ensemble des couples : au plus n couples, vide si et seulement si la chaîne est sans régression.
     * Balayage par première séquence : O(n log n).
     *
     * @param list<KeyRun> $runs
     *
     * @return list<array{0: ?string, 1: ?string}>
     */
    public static function conflicts(array $runs): array
    {
        self::assertWellFormed($runs);

        $null = null;
        $keyed = [];
        foreach ($runs as $run) {
            if ($run->keyId === null) {
                $null = $run;
            } else {
                $keyed[] = $run;
            }
        }
        usort($keyed, static fn (KeyRun $a, KeyRun $b): int => $a->first <=> $b->first);

        $conflicts = [];
        $reach = null; // l'intervalle qui va le plus loin parmi ceux déjà vus
        foreach ($keyed as $run) {
            if ($reach !== null && $run->first <= $reach->last) {
                $conflicts[] = [$reach->keyId, $run->keyId];
            }
            if ($reach === null || $run->last > $reach->last) {
                $reach = $run;
            }
        }
        // NULL doit finir avant que la première clé ne commence.
        if ($null !== null && $keyed !== [] && $keyed[0]->first < $null->last) {
            $conflicts[] = [$keyed[0]->keyId, null];
        }

        return $conflicts;
    }

    /** @param list<KeyRun> $runs */
    public static function hasRegression(array $runs): bool
    {
        return self::conflicts($runs) !== [];
    }

    /**
     * Refus de sceller (D-5) : `null` si la chaîne peut être prolongée avec la clé active, sinon la raison.
     *  - ACTIVE_KEY_LEFT : la chaîne a déjà quitté la clé active (une autre clé finit après son début) ;
     *  - LAST_LINK_REGRESSED : le dernier maillon est lui-même en régression (forge posée en fin de chaîne).
     * Une régression ancienne, sans effet sur le dernier maillon ni sur la clé active, ne bloque pas ici :
     * la vérification et `check-keys` la signalent.
     *
     * @param list<KeyRun> $runs
     *
     * @return ?array{reason: string, keyId: ?string, otherKeyId: ?string}
     */
    public static function extensionBlock(array $runs, string $activeKeyId): ?array
    {
        self::assertWellFormed($runs);
        if ($runs === []) {
            return null;
        }

        foreach ($runs as $run) {
            if ($run->keyId === $activeKeyId) {
                $other = self::latestEndingAfter($runs, $run->keyId, $run->first);
                if ($other !== null) {
                    return ['reason' => self::BLOCK_ACTIVE_KEY_LEFT, 'keyId' => $activeKeyId, 'otherKeyId' => $other->keyId];
                }
            }
        }

        $lastRun = $runs[0];
        foreach ($runs as $run) {
            if ($run->last > $lastRun->last) {
                $lastRun = $run;
            }
        }
        if ($lastRun->keyId === null) {
            foreach ($runs as $run) {
                if ($run->keyId !== null) {
                    return ['reason' => self::BLOCK_LAST_LINK_REGRESSED, 'keyId' => null, 'otherKeyId' => $run->keyId];
                }
            }

            return null;
        }
        $other = self::latestEndingAfter($runs, $lastRun->keyId, $lastRun->first);
        if ($other !== null) {
            return ['reason' => self::BLOCK_LAST_LINK_REGRESSED, 'keyId' => $lastRun->keyId, 'otherKeyId' => $other->keyId];
        }

        return null;
    }

    /**
     * L'intervalle d'un AUTRE identifiant dont la dernière séquence est la plus grande et dépasse `$after`.
     *
     * @param list<KeyRun> $runs
     */
    private static function latestEndingAfter(array $runs, ?string $exceptKeyId, int $after): ?KeyRun
    {
        $found = null;
        foreach ($runs as $run) {
            if ($run->keyId !== $exceptKeyId && $run->last > $after && ($found === null || $run->last > $found->last)) {
                $found = $run;
            }
        }

        return $found;
    }

    /** @param mixed $runs */
    private static function assertWellFormed(mixed $runs): void
    {
        if (!\is_array($runs) || !array_is_list($runs)) {
            throw new \InvalidArgumentException('SealKeyOrder : une liste indexée de KeyRun est attendue.');
        }
        $seen = [];
        foreach ($runs as $run) {
            if (!$run instanceof KeyRun) {
                throw new \InvalidArgumentException('SealKeyOrder : la liste ne contient pas que des KeyRun.');
            }
            $name = $run->keyId === null ? 'n' : 'k' . $run->keyId;
            if (isset($seen[$name])) {
                throw new \InvalidArgumentException('SealKeyOrder : un seul intervalle par identifiant de clé (NULL compris).');
            }
            $seen[$name] = true;
        }
    }
}
