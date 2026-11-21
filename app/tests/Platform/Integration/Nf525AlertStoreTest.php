<?php

declare(strict_types=1);

namespace App\Tests\Platform\Integration;

use App\Caisse\Entity\PointDeVente;
use App\Platform\Nf525\Alert\Nf525AlertConnection;
use App\Platform\Nf525\Alert\Nf525AlertStore;
use App\Platform\Nf525\Alert\OpenedAlert;
use App\Platform\Nf525\SealAlertOrigin;
use App\Platform\Nf525\SealChainRef;
use App\Platform\Nf525\SealRefusalCause;
use App\Tests\SocleApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * LE MAGASIN D'ALERTES ÉCRIT-IL SUR UNE SECONDE SESSION, SANS DOUBLON, SANS RIEN AVALER ?
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - « L'alerte survit à l'annulation » ne prouve rien sans témoin : la ligne écrite par la connexion
 *   PRINCIPALE dans la même transaction doit, elle, disparaître (testG2…).
 * - Un verrou croisé (la transaction principale tient la ligne d'établissement que l'alerte veut lier) se
 *   termine en 1205 APRÈS le délai de la session, et la transaction de la seconde connexion reste ouverte
 *   si on ne l'annule pas : on vérifie qu'aucune session ne traîne (INNODB_TRX).
 * - Les tests qui ouvrent une transaction sur la principale la ferment en tearDown.
 */
final class Nf525AlertStoreTest extends SocleApiTestCase
{
    private function db(): Connection
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em->getConnection();
    }

    private function store(int $lockWaitSeconds = Nf525AlertConnection::DEFAULT_LOCK_WAIT_SECONDS): Nf525AlertStore
    {
        return new Nf525AlertStore(new Nf525AlertConnection($this->db(), $lockWaitSeconds));
    }

    private function chain(string $label = 'Piscine'): SealChainRef
    {
        return SealChainRef::forSales((new PointDeVente())->setLibelle($label));
    }

    protected function tearDown(): void
    {
        $db = $this->db();
        while ($db->isTransactionActive()) {
            $db->rollBack();
        }
        parent::tearDown();
    }

    private function insertBareEstablishment(string $name): Uuid
    {
        $id = Uuid::v4();
        $this->db()->executeStatement(
            'INSERT INTO org_etablissement (id, nom, actif, region_id) SELECT ?, ?, 1, region_id FROM org_etablissement LIMIT 1',
            [$id->toBinary(), $name],
        );

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function alerts(string $chainKey): array
    {
        return $this->db()->fetchAllAssociative('SELECT * FROM platform_nf525_alert WHERE chain_key = ? ORDER BY first_event_at, id', [$chainKey]);
    }

    private function countLinks(string $alertId): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_nf525_alert_establishment WHERE alert_id = ?', [Uuid::fromString($alertId)->toBinary()]);
    }

    private function open(Nf525AlertStore $store, SealChainRef $chain, SealAlertOrigin $origin = SealAlertOrigin::Refusal, array $establishments = [], string $detail = 'detail'): OpenedAlert
    {
        return $store->open($chain, SealRefusalCause::InvalidSignature, $origin, $detail, $establishments);
    }

    public function testThreeOpensOnTheSameChainMakeOneAlertCountedThreeTimes(): void
    {
        $store = $this->store();
        $chain = $this->chain();

        $first = $this->open($store, $chain);
        $second = $this->open($store, $chain);
        $third = $this->open($store, $chain);

        self::assertTrue($first->opened);
        self::assertFalse($second->opened);
        self::assertFalse($third->opened);
        self::assertSame($first->reference, $second->reference);
        self::assertSame($first->alertId, $third->alertId);
        self::assertMatchesRegularExpression('/^NF525-[0-9A-F]{8}$/D', $first->reference);

        $rows = $this->alerts($chain->key());
        self::assertCount(1, $rows);
        self::assertSame(3, (int) $rows[0]['refusal_count']);
        self::assertSame(0, (int) $rows[0]['detection_count']);
        self::assertSame('open', $rows[0]['status']);
        self::assertLessThanOrEqual($rows[0]['last_event_at'], $rows[0]['first_event_at']);
    }

    public function testTwoChainsMakeTwoAlerts(): void
    {
        $store = $this->store();
        $a = $this->chain('A');
        $b = $this->chain('B');

        $ra = $this->open($store, $a);
        $rb = $this->open($store, $b);

        self::assertTrue($ra->opened);
        self::assertTrue($rb->opened);
        self::assertNotSame($ra->reference, $rb->reference);
        self::assertCount(1, $this->alerts($a->key()));
        self::assertCount(1, $this->alerts($b->key()));
    }

    public function testAnAlertOpenedElsewhereIsCountedNotDuplicated(): void
    {
        $chain = $this->chain();
        $this->db()->executeStatement(
            "INSERT INTO platform_nf525_alert (id, reference, chain_type, chain_key, chain_label, cause, origin, status, first_event_at, last_event_at, technical_detail)
             VALUES (?, 'NF525-AAAAAAAA', 'sales', ?, 'Caisse', 'unknown_key', 'refusal', 'open', '2026-11-18 10:00:00', '2026-11-18 10:00:00', 'x')",
            [Uuid::v4()->toBinary(), $chain->key()],
        );

        $result = $this->open($this->store(), $chain);

        self::assertFalse($result->opened);
        self::assertSame('NF525-AAAAAAAA', $result->reference);
        $rows = $this->alerts($chain->key());
        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['refusal_count']);
        self::assertSame('invalid_signature', $rows[0]['cause']);
    }

    public function testResolveThenOpenMakesANewAlert(): void
    {
        $store = $this->store();
        $chain = $this->chain();
        $first = $this->open($store, $chain);

        self::assertTrue($store->resolve($chain->key(), SealAlertOrigin::Refusal));
        $second = $this->open($store, $chain);

        self::assertTrue($second->opened);
        self::assertNotSame($first->alertId, $second->alertId);
        $rows = $this->alerts($chain->key());
        self::assertCount(2, $rows);
        $statuses = array_column($rows, 'status');
        sort($statuses);
        self::assertSame(['open', 'resolved'], $statuses);
        self::assertNotNull($this->db()->fetchOne('SELECT resolved_at FROM platform_nf525_alert WHERE id = ?', [Uuid::fromString($first->alertId)->toBinary()]));
    }

    public function testResolveOfAnotherOriginDoesNothing(): void
    {
        $store = $this->store();
        $chain = $this->chain();
        $this->open($store, $chain, SealAlertOrigin::Verification);

        self::assertFalse($store->resolve($chain->key(), SealAlertOrigin::Refusal));
        self::assertSame('open', $this->alerts($chain->key())[0]['status']);

        // Témoin : l'origine demandée, elle, ferme.
        self::assertTrue($store->resolve($chain->key(), SealAlertOrigin::Verification));
        self::assertSame('resolved', $this->alerts($chain->key())[0]['status']);
    }

    public function testVerificationPromotesTheOriginAndARefusalNeverDemotesIt(): void
    {
        $store = $this->store();
        $chain = $this->chain();
        $this->open($store, $chain, SealAlertOrigin::Refusal);

        $this->open($store, $chain, SealAlertOrigin::Verification);
        $row = $this->alerts($chain->key())[0];
        self::assertSame('verification', $row['origin']);
        self::assertSame(1, (int) $row['refusal_count']);
        self::assertSame(1, (int) $row['detection_count']);

        $this->open($store, $chain, SealAlertOrigin::Refusal);
        $row = $this->alerts($chain->key())[0];
        self::assertSame('verification', $row['origin']);
        self::assertSame(2, (int) $row['refusal_count']);
        self::assertSame(1, (int) $row['detection_count']);
    }

    public function testTheDetailIsTruncatedToTwoThousandCharacters(): void
    {
        $chain = $this->chain();
        $this->open($this->store(), $chain, SealAlertOrigin::Refusal, [], str_repeat('é', 2500));

        self::assertSame(2000, mb_strlen((string) $this->alerts($chain->key())[0]['technical_detail']));
    }

    public function testTheSecondConnectionSentinelReadsTheSameDatabaseAndTheParamsKeepTheirKeys(): void
    {
        $params = $this->db()->getParams();
        foreach (['host', 'dbname', 'user'] as $key) {
            self::assertArrayHasKey($key, $params, sprintf('getParams() (@internal) a perdu la clé « %s » : revoir Nf525AlertConnection.', $key));
        }

        $main = (string) $this->db()->fetchOne('SELECT DATABASE()');
        $seen = (new Nf525AlertConnection($this->db()))->transactional(static fn (Connection $c): string => (string) $c->fetchOne('SELECT DATABASE()'));

        self::assertNotSame('', $main);
        self::assertSame($main, $seen);
    }

    public function testLinksAreAddedAndADuplicateLinkIsAbsorbed(): void
    {
        $establishment = $this->insertBareEstablishment('Lié');
        $store = $this->store();
        $chain = $this->chain();

        $first = $this->open($store, $chain, SealAlertOrigin::Refusal, [$establishment, $establishment]);
        $this->open($store, $chain, SealAlertOrigin::Refusal, [$establishment]);

        self::assertSame(1, $this->countLinks($first->alertId));
    }

    public function testAnUnknownEstablishmentFailsTheWriteAndLeavesNoAlert(): void
    {
        $chain = $this->chain();

        try {
            $this->open($this->store(), $chain, SealAlertOrigin::Refusal, [Uuid::v4()]);
            self::fail('La clé étrangère (1452) ne doit pas être avalée.');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertCount(0, $this->alerts($chain->key()), 'la transaction de l\'alerte est annulée en entier');
        }
    }

    public function testTheAlertSurvivesTheRollbackOfTheMainTransactionWhileTheMainRowDoesNot(): void
    {
        $chain = $this->chain();
        $store = $this->store();
        $db = $this->db();

        $db->beginTransaction();
        $witness = $this->insertBareEstablishment('Témoin annulé');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM org_etablissement WHERE id = ?', [$witness->toBinary()]));
        $this->open($store, $chain);
        $db->rollBack();

        self::assertCount(1, $this->alerts($chain->key()), 'l\'alerte écrite par la seconde connexion survit');
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM org_etablissement WHERE id = ?', [$witness->toBinary()]), 'le témoin de la principale a bien été annulé');
    }

    /** @return iterable<string, array{string}> */
    public static function lockStatements(): iterable
    {
        yield 'UPDATE hors clé' => ['UPDATE org_etablissement SET nom = nom WHERE id = ?'];
        yield 'FOR UPDATE' => ['SELECT id FROM org_etablissement WHERE id = ? FOR UPDATE'];
    }

    #[DataProvider('lockStatements')]
    public function testACrossedLockTimesOutQuicklyAndLeavesNothingBehind(string $lockSql): void
    {
        $locked = $this->insertBareEstablishment('Verrouillé');
        $chain = $this->chain();
        $db = $this->db();

        $db->beginTransaction();
        $db->executeStatement($lockSql, [$locked->toBinary()]);

        $start = microtime(true);
        try {
            $this->open($this->store(1), $chain, SealAlertOrigin::Refusal, [$locked]);
            self::fail('Le verrou croisé aurait dû échouer en 1205.');
        } catch (LockWaitTimeoutException) {
            self::assertLessThan(3.0, microtime(true) - $start, 'le délai de session (1 s) borne l\'attente');
        }

        // Le ROLLBACK explicite est prouvé par Nf525AlertDoublesTest (ordre des appels), pas par un compteur serveur.
        $db->rollBack();
        self::assertCount(0, $this->alerts($chain->key()), 'aucune ligne d\'alerte laissée');
    }

    public function testALockOnAnotherEstablishmentDoesNotDelayTheWrite(): void
    {
        $locked = $this->insertBareEstablishment('Verrouillé ailleurs');
        $linked = $this->insertBareEstablishment('Lié');
        $chain = $this->chain();
        $db = $this->db();

        $db->beginTransaction();
        $db->executeStatement('UPDATE org_etablissement SET nom = nom WHERE id = ?', [$locked->toBinary()]);

        $start = microtime(true);
        $result = $this->open($this->store(1), $chain, SealAlertOrigin::Refusal, [$linked]);
        $elapsed = microtime(true) - $start;
        $db->rollBack();

        self::assertTrue($result->opened);
        self::assertLessThan(0.9, $elapsed, 'aucun verrou en commun : pas d\'attente');
        self::assertCount(1, $this->alerts($chain->key()));
    }

    public function testAResolvedAlertOnTheSameChainIsNeverCountedByTheUpdatePath(): void
    {
        $store = $this->store();
        $chain = $this->chain();
        $old = $this->open($store, $chain);
        $store->resolve($chain->key(), SealAlertOrigin::Refusal);
        $current = $this->open($store, $chain);
        $this->open($store, $chain); // passe par l'UPDATE

        $oldRow = $this->db()->fetchAssociative('SELECT refusal_count, last_event_at FROM platform_nf525_alert WHERE id = ?', [Uuid::fromString($old->alertId)->toBinary()]);
        $currentRow = $this->db()->fetchAssociative('SELECT refusal_count FROM platform_nf525_alert WHERE id = ?', [Uuid::fromString($current->alertId)->toBinary()]);
        self::assertSame(1, (int) $oldRow['refusal_count'], 'l\'alerte résolue n\'est pas touchée');
        self::assertSame(2, (int) $currentRow['refusal_count']);
    }

    public function testTheUpdatePredicateUsesTheUniqueIndexOnTheVirtualColumn(): void
    {
        $chain = $this->chain();
        $this->open($this->store(), $chain);

        $plan = $this->db()->fetchAllAssociative(
            'EXPLAIN UPDATE platform_nf525_alert SET refusal_count = refusal_count + 1 WHERE open_chain_key = ?',
            [$chain->key()],
        );

        self::assertSame('uniq_nf525_alert_open_chain', $plan[0]['key'] ?? null);
    }

    public function testTheDuplicateReferenceErrorNamesItsIndex(): void
    {
        $this->open($this->store(), $this->chain('A'), SealAlertOrigin::Refusal, [], 'x');
        $reference = (string) $this->db()->fetchOne('SELECT reference FROM platform_nf525_alert LIMIT 1');

        try {
            $this->db()->executeStatement(
                "INSERT INTO platform_nf525_alert (id, reference, chain_type, chain_key, chain_label, cause, origin, status, first_event_at, last_event_at, technical_detail)
                 VALUES (?, ?, 'sales', 'sales:autre', 'Caisse', 'unknown_key', 'refusal', 'open', '2026-11-18 10:00:00', '2026-11-18 10:00:00', 'x')",
                [Uuid::v4()->toBinary(), $reference],
            );
            self::fail('La référence est unique.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            self::assertStringContainsString('uniq_nf525_alert_reference', $e->getMessage(), 'le Store reconnaît la collision de référence à ce nom');
        }
    }

    public function testABurstOfOpensLeavesNoServerConnectionOpen(): void
    {
        $kept = [];
        $factory = static function (Connection $main) use (&$kept): Connection {
            return $kept[] = \Doctrine\DBAL\DriverManager::getConnection($main->getParams(), $main->getConfiguration());
        };
        $store = new Nf525AlertStore(new Nf525AlertConnection($this->db(), 3, $factory));
        $threads = fn (): int => (int) $this->db()->fetchOne("SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'THREADS_CONNECTED'");

        $before = $threads();
        for ($i = 0; $i < 20; ++$i) {
            $this->open($store, $this->chain('R' . $i));
        }
        $after = $threads();

        self::assertCount(20, $kept, 'les 20 connexions sont gardées vivantes côté PHP : seul close() les ferme');
        self::assertLessThan(10, $after - $before, 'sans close(), 20 sessions resteraient ouvertes');
    }
}
