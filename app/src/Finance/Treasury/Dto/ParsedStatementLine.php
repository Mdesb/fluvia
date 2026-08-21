<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Dto;

/**
 * Ligne issue du parsing d'un relevé bancaire (§0.4 du plan) — sortie de
 * `BankStatementParserInterface::parse()`, avant persistance en `BankStatementLine`.
 */
final class ParsedStatementLine
{
    public function __construct(
        public readonly \DateTimeImmutable $operationDate,
        public readonly string $label,
        public readonly string $amount,
        public readonly ?string $reference,
    ) {
    }
}
