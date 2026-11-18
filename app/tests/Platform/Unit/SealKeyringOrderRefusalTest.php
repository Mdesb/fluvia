<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Nf525\KeyRun;
use App\Platform\Nf525\SealCheck;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealRefusalCause;
use App\Platform\Nf525\SealKeyring;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * #369 / G-5 — LES BRANCHES DU REFUS DE SCELLER, une par une, et ce qu'elles ne doivent jamais montrer :
 * la phrase « clé NEUVE » est propre à ACTIVE_KEY_LEFT ; un dernier maillon NULL nomme la clé non NULL qui
 * finit le plus tard ; ni une clé (active comprise) ni une valeur hostile venue de la base ne sort dans un
 * message ou dans le journal ; une entrée inexploitable est un refus (409), jamais une exception brute.
 */
final class SealKeyringOrderRefusalTest extends TestCase
{
    private const KEY_A = 'cle-de-test-A-refus-ordre';
    private const KEY_B = 'cle-de-test-B-refus-ordre';
    private const KEY_C = 'cle-de-test-C-refus-ordre';

    private static function spy(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
    }

    /** @param callable(): void $action */
    private static function refusal(callable $action): SealingRefusedException
    {
        try {
            $action();
        } catch (SealingRefusedException $e) {
            self::assertSame(409, $e->getStatusCode());

            return $e;
        }
        self::fail('Le scellement aurait dû être refusé (409).');
    }

    private static function assertNoSecret(string $text): void
    {
        foreach ([self::KEY_A, self::KEY_B, self::KEY_C] as $key) {
            self::assertStringNotContainsString($key, $text, 'jamais une clé dans un message ou le journal');
        }
    }

    public function testActiveKeyLeftIsTheOnlyBranchThatSaysToUseANewKey(): void
    {
        $logger = self::spy();
        $keyring = new SealKeyring('NF525_SEAL_KEY', self::KEY_C, self::KEY_A . ',' . self::KEY_B, $logger);
        $idA = SealKeyring::keyIdOf(self::KEY_A);
        $idB = SealKeyring::keyIdOf(self::KEY_B);
        $idC = SealKeyring::keyIdOf(self::KEY_C);

        // La chaîne a déjà quitté C : C, puis B.
        $e = self::refusal(static fn () => $keyring->assertCanExtendInOrder([new KeyRun($idC, 1, 1), new KeyRun($idB, 2, 2)], 'chaîne témoin'));
        self::assertSame(SealRefusalCause::KeyRegression, $e->cause());
        self::assertStringContainsString('a déjà quitté la clé de scellement active', $e->technicalDetail());
        self::assertStringContainsString('clé NEUVE', $e->technicalDetail());
        self::assertStringContainsString($idC, $e->technicalDetail(), 'l\'identifiant de la clé active, pas la clé');
        self::assertSame(SealKeyring::keyIdOf(self::KEY_C), $logger->records[0][2]['cle_active']);
        self::assertSame('active_key_left', $logger->records[0][2]['cause']);
        self::assertNoSecret($e->technicalDetail());
        self::assertNoSecret(json_encode($logger->records, \JSON_THROW_ON_ERROR));

        // Dernier maillon en régression (A,B,A) : pas de « clé NEUVE », on renvoie à la vérification.
        $logger->records = [];
        $e = self::refusal(static fn () => $keyring->assertCanExtendInOrder([new KeyRun($idA, 1, 3), new KeyRun($idB, 2, 2)], 'chaîne témoin'));
        self::assertStringNotContainsString('NEUVE', $e->technicalDetail());
        self::assertStringContainsString('verifier-chaine', $e->technicalDetail());
        self::assertStringContainsString('alerte', $e->technicalDetail());
        self::assertSame('last_link_regressed', $logger->records[0][2]['cause']);
        self::assertNoSecret($e->technicalDetail());
        self::assertNoSecret(json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    public function testNullLastLinkNamesTheNonNullKeyThatEndsLatest(): void
    {
        $logger = self::spy();
        $keyring = new SealKeyring('NF525_SEAL_KEY', self::KEY_C, self::KEY_A . ',' . self::KEY_B, $logger);
        $idA = SealKeyring::keyIdOf(self::KEY_A);
        $idB = SealKeyring::keyIdOf(self::KEY_B);

        // A (1-2), B (3-4), puis NULL (5) : la clé nommée est B (finit le plus tard), pas A.
        $e = self::refusal(static fn () => $keyring->assertCanExtendInOrder(
            [new KeyRun($idA, 1, 2), new KeyRun($idB, 3, 4), new KeyRun(null, 5, 5)],
            'chaîne témoin',
        ));
        self::assertStringContainsString('maillon sans identifiant après la clé ' . $idB, $e->technicalDetail());
        self::assertStringNotContainsString($idA, $e->technicalDetail());
        self::assertStringNotContainsString('NEUVE', $e->technicalDetail());
        self::assertSame($idB, $logger->records[0][2]['cle_autre']);
        self::assertNull($logger->records[0][2]['cle_du_maillon']);
        self::assertNoSecret($e->technicalDetail());
        self::assertNoSecret(json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    public function testHostileOtherKeyIdIsSanitisedInMessageAndJournal(): void
    {
        $evil = "x\n<script>alert(1)</script>";
        $logger = self::spy();
        $keyring = new SealKeyring('NF525_SEAL_KEY', self::KEY_C, self::KEY_B, $logger);

        // Dernier maillon NULL après un identifiant hostile.
        $e = self::refusal(static fn () => $keyring->assertCanExtendInOrder([new KeyRun($evil, 1, 2), new KeyRun(null, 3, 3)], 'chaîne témoin'));
        self::assertStringNotContainsString('<script>', $e->technicalDetail());
        self::assertStringNotContainsString("\n", $e->technicalDetail());
        self::assertStringContainsString('identifiant non conforme', $e->technicalDetail());
        $journal = json_encode($logger->records, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('<script>', $journal);
        self::assertStringNotContainsString('\\n', $journal);
    }

    public function testHostileIdsFromTheDatabaseNeverReachProblemOrAssertCanExtend(): void
    {
        $evil = "x\n<script>alert(1)</script>";
        $logger = self::spy();
        $keyring = new SealKeyring('NF525_SEAL_KEY', self::KEY_A, self::KEY_B, $logger);

        $problem = $keyring->problem(SealCheck::UnknownKey, $evil);
        self::assertStringNotContainsString('<script>', (string) $problem);
        self::assertStringNotContainsString("\n", (string) $problem);
        self::assertStringContainsString('identifiant non conforme (longueur ' . strlen($evil), (string) $problem);

        $e = self::refusal(static fn () => $keyring->assertCanExtend('empreinte', 'signature', $evil, 'chaîne témoin'));
        self::assertStringNotContainsString('<script>', $e->technicalDetail());
        self::assertStringNotContainsString("\n", $e->technicalDetail());
        $journal = json_encode($logger->records, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('<script>', $journal);
        self::assertStringNotContainsString('\\n', $journal);
    }

    public function testUnusableRunsBecomeARefusalNotAnException(): void
    {
        $logger = self::spy();
        $keyring = new SealKeyring('NF525_SEAL_KEY', self::KEY_A, self::KEY_B, $logger);

        // La lecture lève (maillon forgé à la séquence 0 : KeyRun refuse ; identifiant de chaîne illisible).
        foreach ([
            static fn (): array => [new KeyRun('0123456789abcdef', 0, 0)],
            static function (): array {
                \Symfony\Component\Uid\Uuid::fromString('');

                return [];
            },
        ] as $loader) {
            $logger->records = [];
            $e = self::refusal(static fn () => $keyring->assertCanExtendInOrderFrom($loader, 'chaîne témoin'));
            self::assertSame(SealRefusalCause::UnreadableOrder, $e->cause());
            self::assertStringContainsString('n\'a pas pu être établi', $e->technicalDetail());
            self::assertCount(1, $logger->records);
            self::assertSame('critical', $logger->records[0][0]);
            self::assertSame('unreadable_order', $logger->records[0][2]['cause']);
            self::assertNoSecret($e->technicalDetail() . json_encode($logger->records, \JSON_THROW_ON_ERROR));
        }

        // Des intervalles mal formés (deux pour le même identifiant) : même refus.
        $logger->records = [];
        $dup = [new KeyRun('0123456789abcdef', 1, 1), new KeyRun('0123456789abcdef', 2, 2)];
        self::refusal(static fn () => $keyring->assertCanExtendInOrder($dup, 'chaîne témoin'));
        self::assertSame('unreadable_order', $logger->records[0][2]['cause']);

        // Témoin positif : une lecture saine passe sans alerte.
        $logger->records = [];
        $keyring->assertCanExtendInOrderFrom(static fn (): array => [new KeyRun(SealKeyring::keyIdOf(self::KEY_A), 1, 3)], 'chaîne témoin');
        self::assertSame([], $logger->records);
    }
}
