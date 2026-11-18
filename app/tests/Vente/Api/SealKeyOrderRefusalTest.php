<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Caisse\Entity\PointDeVente;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealKeyOrder;
use App\Platform\Nf525\SealKeyring;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\HashChainSignataire;
use App\Vente\Nf525\OperationAScellerDto;
use App\Vente\Nf525\ScellementHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * #369 / G-5 + G-7 — LE REFUS DE SCELLER, chaîne de vente, contre la vraie base : `ScellementHandler::sceller`
 * lit les intervalles de clés du point de vente et refuse (409, une alerte critical, rien de scellé) une chaîne
 * qui a quitté la clé active ou dont le dernier maillon est en régression. Les maillons sont insérés en SQL
 * avec des signatures VALIDES (clés étrangères suspendues, le tout dans une transaction annulée) : seule la
 * règle d'ordre peut refuser.
 */
final class SealKeyOrderRefusalTest extends KernelTestCase
{
    private const VARIABLE = 'NF525_SEAL_KEY';
    private const KEYS = ['A' => 'cle-de-test-A-refus-vente', 'B' => 'cle-de-test-B-refus-vente', 'C' => 'cle-de-test-C-refus-vente'];

    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
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

    /**
     * @param list<?string> $names clé de chaque maillon (null : maillon d'avant le trousseau, sans identifiant)
     */
    private function insertChain(PointDeVente $pdv, array $names, int $startSequence = 1): void
    {
        foreach ($names as $offset => $name) {
            $sequence = $startSequence + $offset;
            $fingerprint = hash('sha256', $pdv->getId() . '|' . $sequence);
            $ring = new SealKeyring(self::VARIABLE, $name === null ? self::KEYS['A'] : self::KEYS[$name]);
            $this->db->executeStatement(
                'INSERT INTO nf525_operation_scellee (id, type_operation, cible_type, cible_id, numero_sequence, empreinte, signature, seal_key_id, payload_canonique, horodatage, point_de_vente_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                [
                    Uuid::v7()->toBinary(), 'vente', 'Vente', Uuid::v7()->toBinary(), $sequence, $fingerprint,
                    $name === null ? $ring->signWithoutId($fingerprint) : $ring->sign($fingerprint),
                    $name === null ? null : $ring->activeKeyId(),
                    '{}', $pdv->getId()->toBinary(),
                ],
            );
        }
    }

    /** @param list<string> $retired @return array{0: ScellementHandler, 1: object{records: list<array{string, string, array<mixed>}>}} */
    private function handlerFor(string $active, array $retired): array
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $ring = new SealKeyring(self::VARIABLE, self::KEYS[$active], implode(',', array_map(static fn (string $n): string => self::KEYS[$n], $retired)), $logger);

        return [new ScellementHandler($this->em, new HashChainSignataire($ring), $ring), $logger];
    }

    private static function dto(PointDeVente $pdv): OperationAScellerDto
    {
        return new OperationAScellerDto($pdv, TypeOperationScellee::Vente, 'Vente', Uuid::v4(), ['montant' => '10.00']);
    }

    /** @return array{0: SealingRefusedException, 1: list<array{string, string, array<mixed>}>} */
    private function refused(PointDeVente $pdv, string $active, array $retired): array
    {
        [$handler, $logger] = $this->handlerFor($active, $retired);
        try {
            $handler->sceller(self::dto($pdv));
        } catch (SealingRefusedException $e) {
            self::assertSame(409, $e->getStatusCode());
            foreach (self::KEYS as $key) {
                self::assertStringNotContainsString($key, $e->getMessage() . json_encode($logger->records, \JSON_THROW_ON_ERROR), 'jamais une clé');
            }

            return [$e, $logger->records];
        }
        self::fail('Le scellement aurait dû être refusé (409).');
    }

    /** @return iterable<string, array{string, string, list<string>, ?string}> */
    public static function orderCases(): iterable
    {
        // Témoins négatifs.
        yield 'A,A,B,B actif A' => ['A,A,B,B', 'A', ['B'], SealKeyOrder::BLOCK_ACTIVE_KEY_LEFT];
        yield 'A,B,B,B actif A' => ['A,B,B,B', 'A', ['B'], SealKeyOrder::BLOCK_ACTIVE_KEY_LEFT];
        yield 'A,B,B,A actif C' => ['A,B,B,A', 'C', ['A', 'B'], SealKeyOrder::BLOCK_LAST_LINK_REGRESSED];
        yield 'A,A,A,NULL actif B' => ['A,A,A,NULL', 'B', ['A'], SealKeyOrder::BLOCK_LAST_LINK_REGRESSED];
        yield 'A,A,B,NULL actif C' => ['A,A,B,NULL', 'C', ['A', 'B'], SealKeyOrder::BLOCK_LAST_LINK_REGRESSED];
        // Témoins positifs (G-7 : chaînes légitimes, aucune alerte).
        yield 'NULL,NULL,A,A actif A' => ['NULL,NULL,A,A', 'A', [], null];
        yield 'NULL x4 actif A' => ['NULL,NULL,NULL,NULL', 'A', [], null];
        yield 'A x4 actif A' => ['A,A,A,A', 'A', [], null];
        yield 'A,A,B,B actif B' => ['A,A,B,B', 'B', ['A'], null];
        yield 'A,A,B,B actif C' => ['A,A,B,B', 'C', ['A', 'B'], null];
        yield 'NULL,NULL,A,A actif B' => ['NULL,NULL,A,A', 'B', ['A'], null];
    }

    /** @param list<string> $retired */
    #[\PHPUnit\Framework\Attributes\DataProvider('orderCases')]
    public function testSealingIsRefusedOrAllowedAccordingToTheKeyOrder(string $suite, string $active, array $retired, ?string $expected): void
    {
        $pdv = new PointDeVente();
        $this->insertChain($pdv, array_map(static fn (string $n): ?string => $n === 'NULL' ? null : $n, explode(',', $suite)));

        if ($expected === null) {
            [$handler, $logger] = $this->handlerFor($active, $retired);
            $operation = $handler->sceller(self::dto($pdv));
            self::assertSame(5, $operation->getNumeroSequence());
            self::assertSame(SealKeyring::keyIdOf(self::KEYS[$active]), $operation->getSealKeyId());
            self::assertSame([], $logger->records, 'aucune alerte sur une chaîne légitime');

            return;
        }

        [$e, $records] = $this->refused($pdv, $active, $retired);
        self::assertSame($expected === SealKeyOrder::BLOCK_ACTIVE_KEY_LEFT, str_contains($e->getMessage(), 'NEUVE'), '« clé NEUVE » seulement quand la chaîne a quitté la clé active');
        self::assertCount(1, $records);
        self::assertSame('critical', $records[0][0]);
        self::assertSame($expected, $records[0][2]['cause']);
    }

    public function testAnotherPointOfSaleIsNotBlocked(): void
    {
        $broken = new PointDeVente();
        $healthy = new PointDeVente();
        $this->insertChain($broken, ['A', 'B']);
        $this->insertChain($healthy, ['A', 'A']);

        $this->refused($broken, 'A', ['B']);
        [$handler, $logger] = $this->handlerFor('A', ['B']);
        self::assertSame(3, $handler->sceller(self::dto($healthy))->getNumeroSequence());
        self::assertSame([], $logger->records);
    }

    public function testRotationEndToEndThenBackToTheOldKeyIsRefused(): void
    {
        $pdv = new PointDeVente();
        $this->insertChain($pdv, ['A', 'A']);

        // A actif : se prolonge. Rotation vers B (A retirée) : se prolonge. Les maillons scellés sont écrits en base.
        [$a] = $this->handlerFor('A', []);
        self::assertSame(3, $a->sceller(self::dto($pdv))->getNumeroSequence());
        $this->em->clear();
        [$b] = $this->handlerFor('B', ['A']);
        self::assertSame(3, $b->sceller(self::dto($pdv))->getNumeroSequence(), 'la chaîne en base n\'a que A : B la prolonge');
        $this->insertChain($pdv, ['B', 'B'], 3);
        $this->em->clear();
        self::assertSame(5, $b->sceller(self::dto($pdv))->getNumeroSequence());

        // Retour à A actif : refusé.
        $this->em->clear();
        [$e, $records] = $this->refused($pdv, 'A', ['B']);
        self::assertStringContainsString('NEUVE', $e->getMessage());
        self::assertCount(1, $records);
    }

    public function testLinkForgedAtSequenceZeroIsAConflictNotAServerError(): void
    {
        $pdv = new PointDeVente();
        $this->insertChain($pdv, ['A', 'A']);

        // Témoin positif : sans la forge, la chaîne se prolonge.
        [$handler] = $this->handlerFor('A', []);
        self::assertSame(3, $handler->sceller(self::dto($pdv))->getNumeroSequence());
        $this->em->clear();

        $this->insertChain($pdv, ['A'], 0);
        [$e, $records] = $this->refused($pdv, 'A', []);
        self::assertStringContainsString('n\'a pas pu être établi', $e->getMessage());
        self::assertCount(1, $records);
        self::assertSame('critical', $records[0][0]);
        self::assertSame('unreadable_order', $records[0][2]['cause']);
    }
}
