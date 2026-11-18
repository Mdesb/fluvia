<?php

declare(strict_types=1);

namespace App\Tests\Platform\Nf525;

use App\Platform\Nf525\KeyRun;
use App\Platform\Nf525\SealKeyring;
use App\Platform\Nf525\SealKeyRunQuery;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * #369 — les intervalles de clés d'une chaîne, contre la vraie base, sur les trois chaînes. Les lignes sont
 * insérées en SQL direct (clés étrangères suspendues, le tout dans une transaction annulée) : la requête ne
 * lit que `numero_sequence` et `seal_key_id`.
 *
 * Et la mesure de l'étape 7 : EXPLAIN cite l'index `idx_*_key_seq` et la recherche d'un bord ne lit qu'une
 * ligne, quelle que soit la longueur de la chaîne.
 */
final class SealKeyRunQueryTest extends KernelTestCase
{
    private const ACTIVE = 'cle-active-de-test-ordre';
    private const RETIRED = 'cle-retiree-de-test-ordre';

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = $em->getConnection();
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function chains(): iterable
    {
        yield 'vente' => ['sales'];
        yield 'compta' => ['accounting'];
        yield 'facturation' => ['invoicing'];
    }

    private function keyring(): SealKeyring
    {
        return new SealKeyring('NF525_TEST_SEAL_KEY', self::ACTIVE, self::RETIRED);
    }

    private static function a(): string
    {
        return SealKeyring::keyIdOf(self::ACTIVE);
    }

    private static function b(): string
    {
        return SealKeyring::keyIdOf(self::RETIRED);
    }

    /**
     * Insère une chaîne de maillons (séquences 1..n) portant les identifiants donnés, dans la chaîne $chain
     * dont la référence est $ref (uuid texte, ou [profil, journal] pour la compta).
     *
     * @param list<?string> $keyIds
     * @param string|array{0: string, 1: string} $ref
     */
    private function insertChain(string $chain, string|array $ref, array $keyIds, int $startSequence = 1): void
    {
        foreach ($keyIds as $offset => $keyId) {
            $sequence = $startSequence + $offset;
            $id = Uuid::v7()->toBinary();
            $unused = Uuid::v7()->toBinary();
            match ($chain) {
                'sales' => $this->db->executeStatement(
                    'INSERT INTO nf525_operation_scellee (id, type_operation, cible_type, cible_id, numero_sequence, empreinte, signature, seal_key_id, payload_canonique, horodatage, point_de_vente_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                    [$id, 'vente', 'x', $unused, $sequence, 'e', 's', $keyId, '{}', Uuid::fromString((string) $ref)->toBinary()],
                ),
                'accounting' => $this->db->executeStatement(
                    'INSERT INTO compta_ecriture_comptable (id, date_ecriture, numero_sequence, empreinte, signature, seal_key_id, cree_le, profil_exploitant_id, journal_id, periode_id) VALUES (?, CURDATE(), ?, ?, ?, ?, NOW(), ?, ?, ?)',
                    [$id, $sequence, 'e', 's', $keyId, Uuid::fromString($ref[0])->toBinary(), Uuid::fromString($ref[1])->toBinary(), $unused],
                ),
                'invoicing' => $this->db->executeStatement(
                    'INSERT INTO facturation_facture (id, numero_sequence, seal_key_id, cree_le, profil_exploitant_id, etablissement_id, destinataire_id, cree_par_id) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)',
                    [$id, $sequence, $keyId, Uuid::fromString((string) $ref)->toBinary(), $unused, $unused, $unused],
                ),
            };
        }
    }

    /** @return string|array{0: string, 1: string} une référence de chaîne neuve */
    private function newRef(string $chain): string|array
    {
        return $chain === 'accounting' ? [Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()] : Uuid::v7()->toRfc4122();
    }

    /**
     * @param string|array{0: string, 1: string} $ref
     *
     * @return list<KeyRun>
     */
    private function runs(string $chain, string|array $ref): array
    {
        return match ($chain) {
            'sales' => SealKeyRunQuery::forSales($this->db, $this->keyring(), (string) $ref),
            'accounting' => SealKeyRunQuery::forAccounting($this->db, $this->keyring(), $ref[0], $ref[1]),
            'invoicing' => SealKeyRunQuery::forInvoicing($this->db, $this->keyring(), (string) $ref),
        };
    }

    /**
     * @param list<KeyRun> $runs
     *
     * @return list<string> « id:first-last », triés, pour comparer sans dépendre de l'ordre
     */
    private static function show(array $runs): array
    {
        $out = array_map(static fn (KeyRun $r): string => sprintf('%s:%d-%d', $r->keyId === null ? 'NULL' : self::label($r->keyId), $r->first, $r->last), $runs);
        sort($out);

        return $out;
    }

    private static function label(string $keyId): string
    {
        return match ($keyId) {
            self::a() => 'A',
            self::b() => 'B',
            default => $keyId,
        };
    }

    /** @param list<?string> $keys */
    private function expect(string $chain, array $keys, array $expected): void
    {
        $ref = $this->newRef($chain);
        $this->insertChain($chain, $ref, $keys);
        self::assertSame($expected, self::show($this->runs($chain, $ref)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chains')]
    public function testEmptyChain(string $chain): void
    {
        self::assertSame([], $this->runs($chain, $this->newRef($chain)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chains')]
    public function testNullThenA(string $chain): void
    {
        $this->expect($chain, [null, null, self::a(), self::a(), self::a()], ['A:3-5', 'NULL:1-2']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chains')]
    public function testABAGivesTwoRunsForAAndOneForB(string $chain): void
    {
        // Un intervalle par clé : A couvre 1-3 et B 2-2. SealKeyOrder y lit l'entrelacement (B dans A).
        $this->expect($chain, [self::a(), self::b(), self::a()], ['A:1-3', 'B:2-2']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chains')]
    public function testSingleKeyAndAllNull(string $chain): void
    {
        $this->expect($chain, [self::a(), self::a()], ['A:1-2']);
        $this->expect($chain, [null, null, null], ['NULL:1-3']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chains')]
    public function testEmptyIdAndNullAreOneGroup(string $chain): void
    {
        $this->expect($chain, [null, '', null, '', self::a()], ['A:5-5', 'NULL:1-4']);
        $this->expect($chain, ['', '', self::a()], ['A:3-3', 'NULL:1-2']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chains')]
    public function testIdsThatOnlyDifferByCaseOrTrailingSpaceAreNotMerged(string $chain): void
    {
        // Le moteur (utf8mb4_uca1400_ai_ci, PAD SPACE) tient 'x', 'X' et 'x ' pour égaux : pas nous.
        // Seuls les maillons 1 et 4 portent la vraie clé ; '  ' et ' ' ne sont ni NULL ni '' (aucun intervalle NULL).
        $a = self::a();
        $this->expect($chain, [$a, $a . ' ', strtoupper($a), $a, '  ', ' '], ['A:1-4']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chains')]
    public function testAKeyWithTrailingSpaceAloneIsNotTheKey(string $chain): void
    {
        $a = self::a();
        $this->expect($chain, [$a . ' ', $a . ' '], []);
        $this->expect($chain, [null, $a . ' ', $a], ['A:3-3', 'NULL:1-1']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chains')]
    public function testAnotherChainDoesNotLeak(string $chain): void
    {
        $mine = $this->newRef($chain);
        $other = $this->newRef($chain);
        $this->insertChain($chain, $mine, [null, self::a(), self::a()]);
        $this->insertChain($chain, $other, [self::b(), self::b(), self::a(), null, null, null]);

        self::assertSame(['A:2-3', 'NULL:1-1'], self::show($this->runs($chain, $mine)));
        self::assertSame(['A:3-3', 'B:1-2', 'NULL:4-6'], self::show($this->runs($chain, $other)));
        self::assertSame([], $this->runs($chain, $this->newRef($chain)));
    }

    public function testAccountingJournalsAndProfilesAreSeparateChains(): void
    {
        $profile = Uuid::v7()->toRfc4122();
        $journalOne = Uuid::v7()->toRfc4122();
        $journalTwo = Uuid::v7()->toRfc4122();
        $otherProfile = Uuid::v7()->toRfc4122();
        $this->insertChain('accounting', [$profile, $journalOne], [self::a(), self::a()]);
        $this->insertChain('accounting', [$profile, $journalTwo], [self::b(), self::a(), self::a()]);
        $this->insertChain('accounting', [$otherProfile, $journalOne], [null, null, null, null]);

        $keyring = $this->keyring();
        self::assertSame(['A:1-2'], self::show(SealKeyRunQuery::forAccounting($this->db, $keyring, $profile, $journalOne)));
        self::assertSame(['A:2-3', 'B:1-1'], self::show(SealKeyRunQuery::forAccounting($this->db, $keyring, $profile, $journalTwo)));
        self::assertSame(['NULL:1-4'], self::show(SealKeyRunQuery::forAccounting($this->db, $keyring, $otherProfile, $journalOne)));
    }

    public function testDraftsWithSequenceZeroAreNotChainLinks(): void
    {
        $profile = Uuid::v7()->toRfc4122();
        $this->insertChain('invoicing', $profile, [self::a(), self::a()], 0); // séquences 0 et 1 : le brouillon (0) est hors chaîne
        self::assertSame(['A:1-1'], self::show(SealKeyRunQuery::forInvoicing($this->db, $this->keyring(), $profile)));
    }

    public function testKeyOutsideTheKeyringIsNotSearched(): void
    {
        // Limite D-3 documentée : seuls NULL et les clés du trousseau sont cherchées.
        $ref = $this->newRef('sales');
        $this->insertChain('sales', $ref, [self::a(), 'ffffffffffffffff', self::a()]);
        self::assertSame(['A:1-3'], self::show($this->runs('sales', $ref)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chains')]
    public function testTheIndexServesTheEdgeLookups(string $chain): void
    {
        $ref = $this->newRef($chain);
        $keys = array_merge(array_fill(0, 200, null), array_fill(0, 400, self::a()), array_fill(0, 400, self::b()));
        $this->insertChain($chain, $ref, $keys);

        $index = ['sales' => 'idx_op_pdv_key_seq', 'accounting' => 'idx_ecriture_chain_key_seq', 'invoicing' => 'idx_facture_chain_key_seq'][$chain];
        $table = ['sales' => 'nf525_operation_scellee', 'accounting' => 'compta_ecriture_comptable', 'invoicing' => 'facturation_facture'][$chain];
        $chainPredicate = ['sales' => 'point_de_vente_id = ?', 'accounting' => 'profil_exploitant_id = ? AND journal_id = ?', 'invoicing' => 'profil_exploitant_id = ?'][$chain];
        $chainParams = array_map(static fn (string $u): string => Uuid::fromString($u)->toBinary(), (array) $ref);

        $seen = 0;
        foreach (['ASC', 'DESC'] as $direction) {
            $sql = sprintf(
                'ANALYZE SELECT numero_sequence FROM %s WHERE %s AND numero_sequence > 0 AND seal_key_id = ? AND BINARY seal_key_id = BINARY ? ORDER BY numero_sequence %s LIMIT 1',
                $table,
                $chainPredicate,
                $direction,
            );
            foreach ($this->db->fetchAllAssociative($sql, [...$chainParams, self::a(), self::a()]) as $row) {
                if ($row['table'] === null) {
                    continue;
                }
                ++$seen;
                self::assertSame($index, $row['key'], 'la recherche d\'un bord doit utiliser l\'index de l\'étape 7 : ' . json_encode($row));
                self::assertContains($row['type'], ['ref', 'range']); // range : le filtre numero_sequence > 0 est une borne d'index
                self::assertLessThanOrEqual(2.0, (float) $row['r_rows'], 'un bord se lit en une ligne, pas en parcourant la clé : ' . json_encode($row));
            }
        }
        self::assertSame(2, $seen, 'témoin positif : l\'EXPLAIN a bien rendu une ligne de table par recherche');
    }
}
