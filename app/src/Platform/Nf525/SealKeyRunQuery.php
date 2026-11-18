<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * INTERVALLES DE CLÉS D'UNE CHAÎNE NF525 (#369) : pour chaque clé du trousseau (active et retirées) et pour
 * « sans identifiant », la première et la dernière séquence où UNE chaîne la porte, en une requête. C'est
 * l'entrée de `SealKeyOrder` / `SealKeyring::assertCanExtendInOrder` : le refus de sceller ne relit pas la
 * chaîne.
 *
 * ⚠ PAS `MIN()`/`MAX()`, MAIS `ORDER BY numero_sequence ASC|DESC LIMIT 1`. Mesuré sur MariaDB 11.4 avec
 * les index `idx_*_key_seq` (migration Version20261118120000) : `MIN`/`MAX` lit TOUTES les lignes de la clé
 * (r_rows = 10 000 pour 10 000 maillons, « Using where; Using index »), alors que `ORDER BY … LIMIT 1` en
 * lit UNE (r_rows = 1). C'est ce qui rend le coût du scellement indépendant de la longueur de la chaîne.
 *
 * ⚠ COMPARAISON BINAIRE. `seal_key_id` est en `utf8mb4_uca1400_ai_ci` : insensible à la casse, aux accents et
 * (PAD SPACE) aux espaces finaux, donc `'A' = 'a' = 'A '` pour le moteur. `seal_key_id = ?` seul fusionnerait
 * des identifiants distincts, et un identifiant forgé passerait pour une clé du trousseau. On garde donc
 * `seal_key_id = ?` (il fait servir l'index) ET `BINARY seal_key_id = BINARY ?` (il tranche octet par octet).
 *
 * « SANS IDENTIFIANT » = NULL et '' fusionnés en UN seul intervalle (même convention que `KeyRun` et
 * `SealKeyring::check` ; `SealKeyOrder` refuse deux intervalles pour le même identifiant).
 *
 * SÉQUENCE ≤ 0 : en compta et en facturation, `numero_sequence > 0` exclut les brouillons (séquence 0), donc un
 * maillon forgé à la séquence 0 ou négative y est INVISIBLE au refus de sceller, par construction ; seule la
 * vérification de chaîne le voit. En vente, tout est lu : un maillon à la séquence 0 fait lever `KeyRun`
 * (`\InvalidArgumentException`), que `SealKeyring::assertCanExtendInOrderFrom` convertit en refus (409).
 *
 * LIMITE ASSUMÉE (D-3 du plan) : seuls les identifiants du trousseau sont cherchés. Un identifiant inconnu
 * au milieu d'une chaîne n'est pas vu ici ; la vérification (« clé inconnue ») et `app:nf525:check-keys`
 * (qui lit tous les identifiants) le voient.
 *
 * Requêtes PARAMÉTRÉES : les noms de table et de colonnes sont des constantes de ce fichier, jamais fournis
 * par l'appelant ; les identifiants de chaîne et de clé sont liés. Cloisonnement : la requête est ancrée sur
 * la chaîne demandée (point de vente ; exploitant + journal ; exploitant), jamais sur la table entière.
 */
final class SealKeyRunQuery
{
    /**
     * Vente : une chaîne par point de vente.
     *
     * @return list<KeyRun>
     */
    public static function forSales(Connection $connection, SealKeyring $keyring, string $pointDeVenteId): array
    {
        return self::run(
            $connection,
            $keyring,
            'nf525_operation_scellee',
            'point_de_vente_id = ?',
            [self::binaryId($pointDeVenteId)],
            '1 = 1',
        );
    }

    /**
     * Comptabilité : une chaîne par (exploitant, journal).
     *
     * @return list<KeyRun>
     */
    public static function forAccounting(Connection $connection, SealKeyring $keyring, string $profilId, string $journalId): array
    {
        return self::run(
            $connection,
            $keyring,
            'compta_ecriture_comptable',
            'profil_exploitant_id = ? AND journal_id = ?',
            [self::binaryId($profilId), self::binaryId($journalId)],
            'numero_sequence > 0',
        );
    }

    /**
     * Facturation : une chaîne par exploitant.
     *
     * @return list<KeyRun>
     */
    public static function forInvoicing(Connection $connection, SealKeyring $keyring, string $profilId): array
    {
        return self::run(
            $connection,
            $keyring,
            'facturation_facture',
            'profil_exploitant_id = ?',
            [self::binaryId($profilId)],
            'numero_sequence > 0',
        );
    }

    /**
     * @param list<string> $chainParams
     *
     * @return list<KeyRun>
     */
    private static function run(Connection $connection, SealKeyring $keyring, string $table, string $chainPredicate, array $chainParams, string $sequencePredicate): array
    {
        // Les « clés » cherchées : null (NULL), '' (vide, fusionné avec NULL), puis le trousseau.
        $searched = [null, '', $keyring->activeKeyId(), ...$keyring->retiredKeyIds()];

        $selects = [];
        $params = [];
        foreach ($searched as $index => $keyId) {
            $keyPredicate = $keyId === null ? 'seal_key_id IS NULL' : 'seal_key_id = ? AND BINARY seal_key_id = BINARY ?';
            $sub = static fn (string $direction): string => sprintf(
                '(SELECT numero_sequence FROM %s WHERE %s AND %s AND %s ORDER BY numero_sequence %s LIMIT 1)',
                $table,
                $chainPredicate,
                $sequencePredicate,
                $keyPredicate,
                $direction,
            );
            $selects[] = sprintf('SELECT %d AS key_index, %s AS first_sequence, %s AS last_sequence', $index, $sub('ASC'), $sub('DESC'));
            for ($i = 0; $i < 2; ++$i) { // une fois pour ASC, une fois pour DESC
                array_push($params, ...$chainParams);
                if ($keyId !== null) {
                    $params[] = $keyId;
                    $params[] = $keyId;
                }
            }
        }

        /** @var list<array{key_index: int|string, first_sequence: int|string|null, last_sequence: int|string|null}> $rows */
        $rows = $connection->fetchAllAssociative(implode(' UNION ALL ', $selects), $params);

        // Fusion NULL + '' en un seul intervalle « sans identifiant ».
        /** @var array<int, array{0: int, 1: int}> $bounds par identifiant (clé du tableau = index dans $searched, '' ramené à NULL) */
        $bounds = [];
        foreach ($rows as $row) {
            if ($row['first_sequence'] === null || $row['last_sequence'] === null) {
                continue;
            }
            $index = (int) $row['key_index'];
            $slot = $index === 1 ? 0 : $index; // '' (index 1) rejoint NULL (index 0)
            $first = (int) $row['first_sequence'];
            $last = (int) $row['last_sequence'];
            $bounds[$slot] = isset($bounds[$slot])
                ? [min($bounds[$slot][0], $first), max($bounds[$slot][1], $last)]
                : [$first, $last];
        }

        $runs = [];
        foreach ($bounds as $slot => [$first, $last]) {
            $runs[] = new KeyRun($searched[$slot], $first, $last);
        }

        return $runs;
    }

    private static function binaryId(string $id): string
    {
        return Uuid::fromString($id)->toBinary();
    }
}
