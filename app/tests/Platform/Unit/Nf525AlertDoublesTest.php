<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Caisse\Entity\PointDeVente;
use App\Platform\Nf525\Alert\Nf525AlertConnection;
use App\Platform\Nf525\Alert\Nf525AlertOpenedListener;
use App\Platform\Nf525\Alert\Nf525AlertStore;
use App\Platform\Nf525\Alert\OpenedAlert;
use App\Platform\Nf525\SealAlertOrigin;
use App\Platform\Nf525\SealChainRef;
use App\Platform\Nf525\SealRefusalCause;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Uid\Uuid;

/**
 * LES GARANTIES QUE LA BASE NE PEUT PAS MONTRER : ordre rollBack/close, fermeture sur chaque chemin,
 * mot de passe absent des traces, second tour, collision de référence, annulation par le listener.
 *
 * Un double de `Connection` enregistre ses appels : après 1205 la transaction reste ouverte, et `close()`
 * seul suffit à faire monter les compteurs du serveur ; seul l'ordre des appels prouve le `rollBack()`.
 */
final class Nf525AlertDoublesTest extends TestCase
{
    /** @param list<string> $log */
    private function recordingConnection(array &$log, bool $failSessionSet = false): Connection
    {
        $c = $this->createStub(Connection::class);
        $c->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$log, $failSessionSet): int {
            $log[] = 'sql:' . strtok($sql, ' ');
            if ($failSessionSet) {
                throw new \RuntimeException('SET refusé');
            }

            return 1;
        });
        $c->method('beginTransaction')->willReturnCallback(static function () use (&$log): bool {
            $log[] = 'begin';

            return true;
        });
        $c->method('commit')->willReturnCallback(static function () use (&$log): bool {
            $log[] = 'commit';

            return true;
        });
        $c->method('isTransactionActive')->willReturn(true);
        $c->method('rollBack')->willReturnCallback(static function () use (&$log): bool {
            $log[] = 'rollBack';

            return true;
        });
        $c->method('close')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'close';
        });

        return $c;
    }

    private function connectionOver(Connection $double): Nf525AlertConnection
    {
        return new Nf525AlertConnection($this->createStub(Connection::class), 3, static fn (): Connection => $double);
    }

    public function testFailureRollsBackExplicitlyBeforeClosing(): void
    {
        $log = [];
        $connection = $this->connectionOver($this->recordingConnection($log));

        try {
            $connection->transactional(static function (): never {
                throw new \DomainException('1205 simulé');
            });
            self::fail('L\'exception du travail doit remonter.');
        } catch (\DomainException) {
        }

        self::assertSame(['sql:SET', 'begin', 'rollBack', 'close'], $log);
    }

    public function testSuccessCommitsThenClosesWithoutRollBack(): void
    {
        $log = [];
        $connection = $this->connectionOver($this->recordingConnection($log));

        self::assertSame('ok', $connection->transactional(static fn (): string => 'ok'));

        self::assertSame(['sql:SET', 'begin', 'commit', 'close'], $log);
    }

    public function testTheConnectionIsClosedWhenTheSessionSetupFails(): void
    {
        $log = [];
        $connection = $this->connectionOver($this->recordingConnection($log, failSessionSet: true));

        try {
            $connection->transactional(static fn (): string => 'jamais');
            self::fail('Une ouverture en échec doit lever.');
        } catch (\RuntimeException $e) {
            self::assertNull($e->getPrevious());
        }

        self::assertSame(['sql:SET', 'close'], $log);
    }

    public function testAConnectionFailureLeaksNeitherThePasswordNorAPreviousException(): void
    {
        $password = 'S3cretPwd-Zx81';
        $main = DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'nobody', 'password' => $password, 'dbname' => 'none',
        ]);
        self::assertSame($password, $main->getParams()['password'], 'témoin : le mot de passe est bien dans getParams()');

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $message . ' ' . json_encode($context);
            }
        };

        try {
            (new Nf525AlertConnection($main, 1, null, $logger))->transactional(static fn (): string => 'jamais');
            self::fail('Un hôte injoignable doit lever.');
        } catch (\RuntimeException $e) {
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString($password, (string) $e, 'ni message ni trace ne portent le mot de passe');
        }

        self::assertNotEmpty($logger->lines, 'l\'échec est journalisé');
        self::assertStringContainsString('ConnectionException', implode("\n", $logger->lines), 'la classe d\'origine est journalisée');
        self::assertStringNotContainsString($password, implode("\n", $logger->lines));
    }

    private function chain(): SealChainRef
    {
        return SealChainRef::forSales((new PointDeVente())->setLibelle('Double'));
    }

    private function duplicate(string $key): UniqueConstraintViolationException
    {
        $driver = new class ("Duplicate entry 'x' for key '$key'") extends AbstractException {
        };

        return new UniqueConstraintViolationException($driver, null);
    }

    public function testASecondRoundOpensTheAlertWhenItWasClosedBetweenTheInsertAndTheUpdate(): void
    {
        $inserts = 0;
        $updates = 0;
        $double = $this->createStub(Connection::class);
        $double->method('isTransactionActive')->willReturn(true);
        $double->method('executeStatement')->willReturnCallback(function (string $sql) use (&$inserts, &$updates): int {
            if (preg_match('/^INSERT INTO platform_nf525_alert\s/', $sql) === 1) {
                if (++$inserts === 1) {
                    throw $this->duplicate('uniq_nf525_alert_open_chain');
                }
            } elseif (str_starts_with($sql, 'UPDATE')) {
                ++$updates;

                return 0; // refermée entre-temps
            }

            return 1;
        });

        $result = (new Nf525AlertStore($this->connectionOver($double)))
            ->open($this->chain(), SealRefusalCause::InvalidSignature, SealAlertOrigin::Refusal, 'd', []);

        self::assertTrue($result->opened);
        self::assertSame([2, 1], [$inserts, $updates]);
    }

    public function testThreeReferenceCollisionsInARowFailAndAFourthChanceIsNotTaken(): void
    {
        $calls = 0;
        $double = $this->createStub(Connection::class);
        $double->method('isTransactionActive')->willReturn(true);
        $double->method('executeStatement')->willReturnCallback(function (string $sql): int {
            if (preg_match('/^INSERT INTO platform_nf525_alert\s/', $sql) === 1) {
                throw $this->duplicate('uniq_nf525_alert_reference');
            }

            return 1;
        });
        $store = new Nf525AlertStore($this->connectionOver($double), null, static function () use (&$calls): string {
            ++$calls;

            return 'NF525-AAAAAAAA';
        });

        try {
            $store->open($this->chain(), SealRefusalCause::InvalidSignature, SealAlertOrigin::Refusal, 'd', []);
            self::fail('Trois collisions de suite doivent échouer.');
        } catch (\RuntimeException) {
            self::assertSame(3, $calls);
        }
    }

    public function testAReferenceCollisionIsRetriedWithANewReference(): void
    {
        $refs = ['NF525-AAAAAAAA', 'NF525-BBBBBBBB'];
        $double = $this->createStub(Connection::class);
        $double->method('isTransactionActive')->willReturn(true);
        $double->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            if (preg_match('/^INSERT INTO platform_nf525_alert\s/', $sql) === 1 && $params['reference'] === 'NF525-AAAAAAAA') {
                throw $this->duplicate('uniq_nf525_alert_reference');
            }

            return 1;
        });

        $result = (new Nf525AlertStore($this->connectionOver($double), null, static function () use (&$refs): string { return array_shift($refs) ?? 'NF525-CCCCCCCC'; }))
            ->open($this->chain(), SealRefusalCause::InvalidSignature, SealAlertOrigin::Refusal, 'd', []);

        self::assertSame('NF525-BBBBBBBB', $result->reference);
        self::assertTrue($result->opened);
    }

    public function testTheListenerRunsOnlyOnOpeningAndItsFailureRollsEverythingBack(): void
    {
        $log = [];
        $double = $this->recordingConnection($log);
        $listener = new class implements Nf525AlertOpenedListener {
            public int $calls = 0;

            public function onOpened(Connection $alertConnection, OpenedAlert $alert, SealChainRef $chain, array $establishmentIds): void
            {
                ++$this->calls;
                throw new \LogicException('notification impossible');
            }
        };

        try {
            (new Nf525AlertStore($this->connectionOver($double), $listener))
                ->open($this->chain(), SealRefusalCause::InvalidSignature, SealAlertOrigin::Refusal, 'd', []);
            self::fail('L\'exception du listener doit remonter.');
        } catch (\LogicException) {
        }

        self::assertSame(1, $listener->calls);
        self::assertSame(['sql:SET', 'begin', 'sql:INSERT', 'rollBack', 'close'], $log, 'annulé, jamais validé');
    }

    public function testTheTimeBudgetStopsTheLinksAndRollsBack(): void
    {
        $log = [];
        $double = $this->recordingConnection($log);
        $store = new Nf525AlertStore(new Nf525AlertConnection($this->createStub(Connection::class), 0, static fn (): Connection => $double));

        $this->expectException(\RuntimeException::class);
        try {
            $store->open($this->chain(), SealRefusalCause::InvalidSignature, SealAlertOrigin::Refusal, 'd', [Uuid::v4()]);
        } finally {
            self::assertSame(['sql:SET', 'begin', 'sql:INSERT', 'rollBack', 'close'], $log);
        }
    }
}
