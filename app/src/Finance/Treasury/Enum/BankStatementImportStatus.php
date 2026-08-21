<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Enum;

/** Cycle de vie d'un import de relevé bancaire (§1 du plan, D5). */
enum BankStatementImportStatus: string
{
    case Imported = 'imported';
    case Processed = 'processed';
    case Error = 'error';
}
