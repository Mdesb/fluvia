<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Espion de journal (tests seulement) : décore le service `logger` du noyau, retransmet chaque ligne au
 * vrai journal (la sortie des tests reste inchangée) et en garde une copie en mémoire. Sert à prouver
 * qu'un secret ne passe dans AUCUNE ligne de journal (message ou contexte), exceptions comprises.
 *
 * Toujours accompagné d'un témoin positif dans le test : une ligne connue doit y figurer, sinon
 * l'absence du secret ne prouve rien.
 */
final class LogSpy extends AbstractLogger
{
    /**
     * Statique : le client de test redémarre le noyau entre deux requêtes, donc l'espion est reconstruit ;
     * la copie doit survivre. Le test appelle `reset()` en début de scénario.
     *
     * @var list<string>
     */
    private static array $lines = [];

    public function __construct(private readonly LoggerInterface $inner)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        self::$lines[] = sprintf('%s %s %s', (string) $level, (string) $message, self::flatten($context));
        $this->inner->log($level, $message, $context);
    }

    /** @return list<string> */
    public static function lines(): array
    {
        return self::$lines;
    }

    public static function dump(): string
    {
        return implode("\n", self::$lines);
    }

    public static function reset(): void
    {
        self::$lines = [];
    }

    /** @param array<mixed> $context */
    private static function flatten(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . match (true) {
                \is_scalar($value), $value === null => (string) $value,
                $value instanceof \Throwable => $value::class . ':' . $value->getMessage() . "\n" . $value->getTraceAsString(),
                \is_array($value) => self::flatten($value),
                $value instanceof \Stringable => (string) $value,
                default => get_debug_type($value),
            };
        }

        return implode(' ', $parts);
    }
}
