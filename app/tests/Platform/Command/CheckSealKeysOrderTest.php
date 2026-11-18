<?php

declare(strict_types=1);

namespace App\Tests\Platform\Command;

use App\Platform\Command\CheckSealKeysCommand;
use App\Platform\Nf525\SealKeyring;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * #369 / G-6 — `app:nf525:check-keys` évalue la règle d'ordre sur TOUTE la longueur de chaque chaîne, contre la
 * vraie base, sur les trois chaînes. Les lignes sont insérées en SQL direct (clés étrangères suspendues, le tout
 * dans une transaction annulée). Trousseaux construits ici : A active, B retirée, les autres inconnues.
 *
 * Le point : `A,B,A` dont le DERNIER maillon est valide (signé par la clé active) échoue quand même ; les
 * identifiants hors trousseau au milieu d'une chaîne, que le refus de sceller ne voit pas (D-3), sont vus.
 */
final class CheckSealKeysOrderTest extends KernelTestCase
{
    private const KEY_A = 'cle-active-de-test-check-keys';
    private const KEY_B = 'cle-retiree-de-test-check-keys';
    private const UNKNOWN_ID = 'cccccccccccccccc';

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = $em->getConnection();
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->beginTransaction();
        // La base de test peut garder des maillons laissés par d'autres suites : on part d'un état vide (annulé au tearDown).
        foreach (['nf525_operation_scellee', 'compta_ecriture_comptable', 'facturation_facture'] as $table) {
            $this->db->executeStatement('DELETE FROM ' . $table);
        }
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

    private function tester(): CommandTester
    {
        $ring = static fn (string $variable): SealKeyring => new SealKeyring($variable, self::KEY_A, self::KEY_B);

        return new CommandTester(new CheckSealKeysCommand(
            $this->db,
            $ring('NF525_SEAL_KEY'),
            $ring('NF525_COMPTA_SEAL_KEY'),
            $ring('NF525_FACTURATION_SEAL_KEY'),
        ));
    }

    /** @return string|array{0: string, 1: string} */
    private function newRef(string $chain): string|array
    {
        return $chain === 'accounting' ? [Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()] : Uuid::v7()->toRfc4122();
    }

    /** @param string|array{0: string, 1: string} $ref */
    private static function refText(string|array $ref): string
    {
        return \is_array($ref)
            ? str_replace('-', '', $ref[0]) . '/' . str_replace('-', '', $ref[1])
            : str_replace('-', '', $ref);
    }

    /**
     * Insère une chaîne de maillons 1..n. `A` et `B` : signés pour de bon par la clé (maillon vérifiable) ;
     * `null` : signature sans identifiant par A ; tout autre identifiant : signature quelconque (clé hors trousseau).
     *
     * @param list<?string> $keyIds 'A', 'B', null, ou un identifiant brut
     * @param string|array{0: string, 1: string} $ref
     */
    private function insertChain(string $chain, string|array $ref, array $keyIds): void
    {
        foreach ($keyIds as $offset => $name) {
            $sequence = $offset + 1;
            $fingerprint = hash('sha256', 'maillon-' . $sequence);
            $keyId = match ($name) {
                'A' => SealKeyring::keyIdOf(self::KEY_A),
                'B' => SealKeyring::keyIdOf(self::KEY_B),
                default => $name,
            };
            $signature = match ($name) {
                'A' => (new SealKeyring('X', self::KEY_A))->sign($fingerprint),
                'B' => (new SealKeyring('X', self::KEY_B))->sign($fingerprint),
                null => (new SealKeyring('X', self::KEY_A))->signWithoutId($fingerprint),
                default => 'signature-quelconque',
            };
            $id = Uuid::v7()->toBinary();
            $unused = Uuid::v7()->toBinary();
            match ($chain) {
                'sales' => $this->db->executeStatement(
                    'INSERT INTO nf525_operation_scellee (id, type_operation, cible_type, cible_id, numero_sequence, empreinte, signature, seal_key_id, payload_canonique, horodatage, point_de_vente_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                    [$id, 'vente', 'x', $unused, $sequence, $fingerprint, $signature, $keyId, '{}', Uuid::fromString((string) $ref)->toBinary()],
                ),
                'accounting' => $this->db->executeStatement(
                    'INSERT INTO compta_ecriture_comptable (id, date_ecriture, numero_sequence, empreinte, signature, seal_key_id, cree_le, profil_exploitant_id, journal_id, periode_id) VALUES (?, CURDATE(), ?, ?, ?, ?, NOW(), ?, ?, ?)',
                    [$id, $sequence, $fingerprint, $signature, $keyId, Uuid::fromString($ref[0])->toBinary(), Uuid::fromString($ref[1])->toBinary(), $unused],
                ),
                'invoicing' => $this->db->executeStatement(
                    'INSERT INTO facturation_facture (id, numero_sequence, empreinte, signature, seal_key_id, cree_le, profil_exploitant_id, etablissement_id, destinataire_id, cree_par_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)',
                    [$id, $sequence, $fingerprint, $signature, $keyId, Uuid::fromString((string) $ref)->toBinary(), $unused, $unused, $unused],
                ),
            };
        }
    }

    #[DataProvider('chains')]
    public function testEmptyBaseAndHealthyChainsPass(string $chain): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $this->insertChain($chain, $this->newRef($chain), ['A', 'A', 'A']);
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
    }

    /** Témoin positif : la rotation légitime, jamais refusée. */
    #[DataProvider('chains')]
    public function testLegitimateRotationNullThenAThenBPasses(string $chain): void
    {
        $this->insertChain($chain, $this->newRef($chain), [null, 'A', 'B']);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringNotContainsString('✗', $tester->getDisplay());
    }

    /** LE CAS DE LA SPEC : le dernier maillon est valide (signé par la clé active), la chaîne est en régression. */
    #[DataProvider('chains')]
    public function testKeyReturningAfterLeavingFailsEvenWithAValidLastLink(string $chain): void
    {
        $ref = $this->newRef($chain);
        $this->insertChain($chain, $ref, ['A', 'B', 'A']);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $out = $tester->getDisplay();
        self::assertStringContainsString(self::refText($ref), $out, 'la chaîne fautive est nommée');
        self::assertStringContainsString('s\'entrelacent', $out);
        self::assertStringContainsString(SealKeyring::keyIdOf(self::KEY_A), $out, 'les identifiants (non réversibles) sont nommés');
        self::assertStringNotContainsString(self::KEY_A, $out, 'jamais la clé');
        self::assertStringNotContainsString(self::KEY_B, $out, 'jamais la clé');
    }

    #[DataProvider('chains')]
    public function testNullAfterSignedLinkFails(string $chain): void
    {
        $this->insertChain($chain, $this->newRef($chain), ['A', null, 'A']);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('sans identifiant', $tester->getDisplay());
    }

    /** Un identifiant HORS TROUSSEAU au milieu d'une chaîne : le refus de sceller ne le voit pas, la commande oui. */
    #[DataProvider('chains')]
    public function testUnknownIdInTheMiddleOfAChainIsSeen(string $chain): void
    {
        $this->insertChain($chain, $this->newRef($chain), ['A', self::UNKNOWN_ID, 'A']);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('s\'entrelacent', $tester->getDisplay());
        self::assertStringContainsString(self::UNKNOWN_ID, $tester->getDisplay());
    }

    /** Un identifiant forgé n'est jamais recopié tel quel : décrit (longueur, empreinte tronquée). */
    #[DataProvider('chains')]
    public function testForgedIdIsNeverEchoed(string $chain): void
    {
        $forged = "A\n<script>alert(1)</script>";
        $this->insertChain($chain, $this->newRef($chain), ['A', $forged, 'A']);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringNotContainsString('<script>', $tester->getDisplay());
        self::assertStringContainsString('identifiant non conforme', $tester->getDisplay());
    }

    /** Plusieurs chaînes dont UNE seule fautive : échec, et seule la fautive est nommée. */
    #[DataProvider('chains')]
    public function testOnlyTheFaultyChainIsNamed(string $chain): void
    {
        $healthy1 = $this->newRef($chain);
        $faulty = $this->newRef($chain);
        $healthy2 = $this->newRef($chain);
        $this->insertChain($chain, $healthy1, ['A', 'A']);
        $this->insertChain($chain, $faulty, ['A', 'B', 'A']);
        $this->insertChain($chain, $healthy2, [null, 'A', 'B']);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $out = $tester->getDisplay();
        self::assertStringContainsString(self::refText($faulty), $out);
        self::assertStringNotContainsString(self::refText($healthy1), $out);
        self::assertStringNotContainsString(self::refText($healthy2), $out);
        self::assertSame(1, substr_count($out, '  ✗ NF525_'), 'une seule ligne de chaîne fautive');
    }

    /** Les identifiants qui ne diffèrent que par la casse ne sont pas confondus (comparaison octet par octet). */
    #[DataProvider('chains')]
    public function testIdsDifferingOnlyByCaseAreDistinct(string $chain): void
    {
        $this->insertChain($chain, $this->newRef($chain), ['A', 'DDDDDDDDDDDDDDDD', 'A', 'dddddddddddddddd']);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    /**
     * Sous `utf8mb4_uca1400_ai_ci` (PAD SPACE), `'A '` = `'A'` et `'é'` = `'e'` pour le moteur : seul `BINARY` dans le
     * GROUP BY les garde distincts. Fusionnés, `A,A␣,A` ne serait qu'une clé : aucune régression. (On cherche
     * « s'entrelacent », propre à la ligne de chaîne : « régression de clé » figure aussi dans le message final.)
     */
    #[DataProvider('chains')]
    public function testTrailingSpaceDistinguishesTwoIds(string $chain): void
    {
        $keyId = SealKeyring::keyIdOf(self::KEY_A);
        $this->insertChain($chain, $this->newRef($chain), ['A', $keyId . ' ', 'A']);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('s\'entrelacent', $tester->getDisplay());
    }

    #[DataProvider('chains')]
    public function testAccentDistinguishesTwoIds(string $chain): void
    {
        $this->insertChain($chain, $this->newRef($chain), ['ccccccccccccccce', 'cccccccccccccccé', 'ccccccccccccccce', 'A']);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('s\'entrelacent', $tester->getDisplay());
    }

    /** Une chaîne fautive aux DEUX contrôles (dernier maillon invérifiable ET régression) ne compte qu'une fois. */
    #[DataProvider('chains')]
    public function testChainFaultyOnBothChecksIsCountedOnce(string $chain): void
    {
        $this->insertChain($chain, $this->newRef($chain), ['A', self::UNKNOWN_ID, 'A', self::UNKNOWN_ID]);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $out = $tester->getDisplay();
        self::assertStringContainsString('dernier maillon', $out, 'témoin : le contrôle du dernier maillon signale la chaîne');
        self::assertStringContainsString('s\'entrelacent', $out, 'témoin : la règle d\'ordre aussi');
        self::assertStringContainsString('1 bloquée(s)', $out);
        self::assertStringContainsString('1 chaîne(s) NF525 bloquée(s)', preg_replace('/\s+/', ' ', $out));
    }

    /** Lecture seule sur les TROIS tables : ni ligne ajoutée ni valeur changée. */
    public function testCommandIsReadOnly(): void
    {
        $tables = [
            'nf525_operation_scellee' => 'sales',
            'compta_ecriture_comptable' => 'accounting',
            'facturation_facture' => 'invoicing',
        ];
        foreach ($tables as $chain) {
            $this->insertChain($chain, $this->newRef($chain), ['A', 'B', 'A', null, self::UNKNOWN_ID]);
        }
        $snapshot = function () use ($tables): array {
            $out = [];
            foreach (array_keys($tables) as $table) {
                $out[$table] = $this->db->fetchNumeric(sprintf('SELECT COUNT(*), SUM(CRC32(CONCAT(numero_sequence, IFNULL(seal_key_id, \'\'), empreinte, signature))) FROM %s', $table));
            }

            return $out;
        };
        $before = $snapshot();
        self::assertNotSame([], array_filter($before, static fn (array $r): bool => (int) $r[0] > 0), 'témoin : des lignes existent');

        $this->tester()->execute([]);

        self::assertEquals($before, $snapshot());
    }
}
