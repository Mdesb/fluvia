<?php

declare(strict_types=1);

namespace App\Import\Enum;

/**
 * Cycle de vie d'un lot d'import (`ImportBatch`, plan-import-i1.md §1, D5). `Pending` est réservé à un
 * futur mode asynchrone (§7 point 9 du plan, D7) — jamais observé en base par ce lot, la validation
 * étant synchrone dans le même appel HTTP.
 */
enum ImportBatchStatus: string
{
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Validated = 'validated';
    case Applied = 'applied';
    case Reverted = 'reverted';
}
