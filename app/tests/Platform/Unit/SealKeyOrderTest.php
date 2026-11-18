<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Nf525\KeyRegression;
use App\Platform\Nf525\KeyRun;
use App\Platform\Nf525\SealKeyOrder;
use App\Platform\Nf525\SealKeyOrderTracker;
use PHPUnit\Framework\TestCase;

/**
 * #369 — l'ordre des clés dans une chaîne NF525 : une clé quittée ne revient jamais, NULL vient en premier.
 * Deux formes de la même règle (parcours, intervalles) ; le test exhaustif garantit qu'elles ne dérivent pas.
 */
final class SealKeyOrderTest extends TestCase
{
    /**
     * @param list<?string> $keys une clé par maillon, séquences 1..n
     *
     * @return list<KeyRegression>
     */
    private static function trace(array $keys): array
    {
        $tracker = new SealKeyOrderTracker();
        $found = [];
        foreach ($keys as $i => $key) {
            $regression = $tracker->feed($i + 1, $key);
            if ($regression !== null) {
                $found[] = $regression;
            }
        }

        return $found;
    }

    /**
     * @param list<?string> $keys
     *
     * @return list<KeyRun>
     */
    private static function runs(array $keys): array
    {
        $byKey = [];
        foreach ($keys as $i => $key) {
            $key = $key === '' ? null : $key;
            $name = $key ?? '#null';
            $byKey[$name] = isset($byKey[$name])
                ? new KeyRun($key, $byKey[$name]->first, $i + 1)
                : new KeyRun($key, $i + 1, $i + 1);
        }

        return array_values($byKey);
    }

    /** @return iterable<string, array{list<?string>}> */
    public static function legitimateChains(): iterable
    {
        yield 'vide' => [[]];
        yield 'tout NULL' => [[null, null, null]];
        yield 'une seule clé' => [['A', 'A', 'A']];
        yield 'NULL puis A' => [[null, null, 'A', 'A']];
        yield 'A puis B puis C' => [['A', 'A', 'B', 'C', 'C']];
        yield 'NULL, A, B' => [[null, 'A', 'B']];
        yield 'A,A,B,B' => [['A', 'A', 'B', 'B']];
    }

    /** @param list<?string> $keys */
    #[\PHPUnit\Framework\Attributes\DataProvider('legitimateChains')]
    public function testLegitimateChainsHaveNoRegression(array $keys): void
    {
        self::assertSame([], self::trace($keys));
        self::assertFalse(SealKeyOrder::hasRegression(self::runs($keys)));
    }

    public function testReturnedKeyIsReportedAtItsSequence(): void
    {
        $found = self::trace(['A', 'A', 'B', 'A']);

        self::assertCount(1, $found);
        self::assertSame(KeyRegression::RETURNED_KEY, $found[0]->kind);
        self::assertSame(4, $found[0]->sequence);
        self::assertSame('A', $found[0]->keyId);
        self::assertSame('B', $found[0]->previousKeyId);
        self::assertTrue(SealKeyOrder::hasRegression(self::runs(['A', 'A', 'B', 'A'])));
    }

    public function testNullAfterKeyIsReportedAtItsSequence(): void
    {
        $found = self::trace(['A', 'A', 'B', null]);

        self::assertCount(1, $found);
        self::assertSame(KeyRegression::NULL_AFTER_KEY, $found[0]->kind);
        self::assertSame(4, $found[0]->sequence);
        self::assertNull($found[0]->keyId);
        self::assertSame('B', $found[0]->previousKeyId);
        self::assertTrue(SealKeyOrder::hasRegression(self::runs(['A', 'A', 'B', null])));
    }

    public function testEmptyIdentifierCountsAsNull(): void
    {
        self::assertSame([], self::trace([null, '', 'A']));
        self::assertCount(1, self::trace(['A', '']));
        self::assertNull((new KeyRun('', 1, 2))->keyId);
    }

    public function testAlternatingKeysAreReportedTwice(): void
    {
        $found = self::trace(['A', 'B', 'A', 'B']);

        self::assertSame([3, 4], array_map(static fn (KeyRegression $r): int => $r->sequence, $found));
    }

    public function testReportedPairsNameKeysOnly(): void
    {
        self::assertSame([['A', 'B']], SealKeyOrder::conflicts(self::runs(['A', 'B', 'A'])));
        self::assertSame([['A', null]], SealKeyOrder::conflicts(self::runs(['A', null])));
        self::assertSame([], SealKeyOrder::conflicts(self::runs([null, 'A', 'B'])));
    }

    /**
     * Dérivation INDÉPENDANTE (sans tracker ni intervalles) de la définition de la spec : le maillon i est en
     * régression s'il porte NULL après une clé, ou une clé déjà portée plus tôt puis quittée.
     *
     * @param list<?string> $keys
     *
     * @return array<int, string> séquence => type
     */
    private static function derive(array $keys): array
    {
        $keys = array_map(static fn (?string $k): ?string => $k === '' ? null : $k, $keys);
        $found = [];
        foreach ($keys as $i => $key) {
            $before = array_slice($keys, 0, $i);
            if ($key === null) {
                if (array_filter($before, static fn (?string $k): bool => $k !== null) !== []) {
                    $found[$i + 1] = KeyRegression::NULL_AFTER_KEY;
                }
                continue;
            }
            $first = array_search($key, $before, true);
            if ($first !== false && array_filter(array_slice($before, $first), static fn (?string $k): bool => $k !== $key) !== []) {
                $found[$i + 1] = KeyRegression::RETURNED_KEY;
            }
        }

        return $found;
    }

    /**
     * Garde contre la dérive : sur toutes les suites de 1 à 6 maillons sur {NULL, '', A, B, C}, la forme
     * parcours, la forme intervalles et la dérivation indépendante disent la même chose.
     */
    public function testTrackerIntervalsAndDerivationAgreeOnEverySmallChain(): void
    {
        $alphabet = [null, '', 'A', 'B', 'C'];
        $checked = 0;
        $withRegression = 0;
        $blocked = 0;

        for ($length = 1; $length <= 6; ++$length) {
            $total = 5 ** $length;
            for ($n = 0; $n < $total; ++$n) {
                $keys = [];
                $rest = $n;
                for ($i = 0; $i < $length; ++$i) {
                    $keys[] = $alphabet[$rest % 5];
                    $rest = intdiv($rest, 5);
                }
                $label = json_encode($keys, \JSON_THROW_ON_ERROR);

                $found = self::trace($keys);
                $runs = self::runs($keys);
                $expected = self::derive($keys);

                self::assertSame($expected !== [], SealKeyOrder::hasRegression($runs), 'verdict divergent sur ' . $label);
                self::assertSame($expected, array_combine(array_map(static fn (KeyRegression $r): int => $r->sequence, $found), array_map(static fn (KeyRegression $r): string => $r->kind, $found)) ?: [], 'régressions divergentes sur ' . $label);
                if ($expected !== []) {
                    self::assertSame(array_key_first($expected), $found[0]->sequence, 'première régression : ' . $label);
                    self::assertSame($expected[array_key_first($expected)], $found[0]->kind, 'type de la première régression : ' . $label);
                }
                ++$checked;
                $withRegression += $expected !== [] ? 1 : 0;

                $normalized = array_map(static fn (?string $k): ?string => $k === '' ? null : $k, $keys);
                $lastRegressed = isset($expected[$length]);
                foreach (['A', 'B', 'C', 'Z'] as $active) {
                    $firstAt = array_search($active, $normalized, true);
                    $activeLeft = $firstAt !== false
                        && array_filter(array_slice($normalized, $firstAt), static fn (?string $k): bool => $k !== $active) !== [];
                    $reason = $activeLeft
                        ? SealKeyOrder::BLOCK_ACTIVE_KEY_LEFT
                        : ($lastRegressed ? SealKeyOrder::BLOCK_LAST_LINK_REGRESSED : null);

                    $block = SealKeyOrder::extensionBlock($runs, $active);
                    self::assertSame($reason, $block['reason'] ?? null, "raison du blocage ($active) : " . $label);
                    $blocked += $reason !== null ? 1 : 0;
                }
            }
        }

        // Témoins : l'énumération a couvert tout l'espace, et trouvé des cas des deux sortes.
        self::assertSame(5 + 25 + 125 + 625 + 3125 + 15625, $checked);
        self::assertGreaterThan(5000, $withRegression);
        self::assertLessThan($checked, $withRegression);
        self::assertGreaterThan(5000, $blocked);
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function malformedInputs(): iterable
    {
        yield 'première > dernière' => [static fn () => new KeyRun('A', 3, 2)];
        yield 'séquence nulle' => [static fn () => new KeyRun('A', 0, 2)];
        yield 'séquence négative' => [static fn () => new KeyRun('A', -1, 2)];
        yield 'identifiant en double' => [static fn () => SealKeyOrder::conflicts([new KeyRun('A', 1, 1), new KeyRun('A', 2, 2)])];
        yield 'NULL et vide en deux runs' => [static fn () => SealKeyOrder::hasRegression([new KeyRun(null, 1, 1), new KeyRun('', 2, 2)])];
        yield 'liste non indexée' => [static fn () => SealKeyOrder::extensionBlock([1 => new KeyRun('A', 1, 1)], 'A')];
        yield 'élément qui n\'est pas un KeyRun' => [static fn () => SealKeyOrder::conflicts([['A', 1, 1]])];
        yield 'doublon vu par extensionBlock' => [static fn () => SealKeyOrder::extensionBlock([new KeyRun('A', 1, 1), new KeyRun('A', 2, 2)], 'B')];
        yield 'séquences non croissantes' => [static function () {
            $tracker = new SealKeyOrderTracker();
            $tracker->feed(2, 'A');
            $tracker->feed(2, 'A');
        }];
        yield 'séquences qui reculent' => [static function () {
            $tracker = new SealKeyOrderTracker();
            $tracker->feed(5, 'A');
            $tracker->feed(4, 'B');
        }];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedInputs')]
    public function testMalformedInputFailsClosed(callable $call): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $call();
    }

    public function testLargeChainIsHandledInOneSweep(): void
    {
        $runs = [];
        for ($i = 1; $i <= 20000; ++$i) {
            $runs[] = new KeyRun(sprintf('%016x', $i), $i * 10, $i * 10 + 9);
        }
        self::assertFalse(SealKeyOrder::hasRegression($runs));
        $runs[] = new KeyRun(sprintf('%016x', 0), 15, 15);
        self::assertTrue(SealKeyOrder::hasRegression($runs));
    }

    /** @return iterable<string, array{list<?string>, string, ?string}> chaîne, clé active, raison attendue */
    public static function extensionCases(): iterable
    {
        yield 'A,B active A : quittée' => [['A', 'B'], 'A', SealKeyOrder::BLOCK_ACTIVE_KEY_LEFT];
        yield 'A,B,A active C : forge en fin' => [['A', 'B', 'A'], 'C', SealKeyOrder::BLOCK_LAST_LINK_REGRESSED];
        yield 'A,B,NULL active C : NULL en fin' => [['A', 'B', null], 'C', SealKeyOrder::BLOCK_LAST_LINK_REGRESSED];
        yield 'A,B active B : passe' => [['A', 'B'], 'B', null];
        yield 'A,B active C : passe' => [['A', 'B'], 'C', null];
        yield 'vide : passe' => [[], 'A', null];
        yield 'tout NULL active A : passe' => [[null, null], 'A', null];
        yield 'NULL puis A active A : passe' => [[null, null, 'A', 'A'], 'A', null];
        yield 'A,B,C active C : passe' => [['A', 'B', 'C'], 'C', null];
        yield 'A,B,A,C active C : ancienne régression, ne bloque pas' => [['A', 'B', 'A', 'C'], 'C', null];
    }

    /** @param list<?string> $keys */
    #[\PHPUnit\Framework\Attributes\DataProvider('extensionCases')]
    public function testExtensionBlock(array $keys, string $active, ?string $reason): void
    {
        $block = SealKeyOrder::extensionBlock(self::runs($keys), $active);

        self::assertSame($reason, $block['reason'] ?? null);
    }

    public function testExtensionBlockNamesIdentifiers(): void
    {
        $block = SealKeyOrder::extensionBlock(self::runs(['A', 'B', 'A']), 'C');

        self::assertSame(['reason' => SealKeyOrder::BLOCK_LAST_LINK_REGRESSED, 'keyId' => 'A', 'otherKeyId' => 'B'], $block);
    }
}
