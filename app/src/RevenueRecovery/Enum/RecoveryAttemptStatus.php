<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Enum;

/** Cycle de vie d'une `RecoveryAttempt` (plan-revenue-recovery.md §1). */
enum RecoveryAttemptStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
