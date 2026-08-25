<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Enum;

/** Cycle de vie d'un `RecoveryCase` (plan-revenue-recovery.md §1). */
enum RecoveryCaseStatus: string
{
    case Active = 'active';
    case Resolved = 'resolved';
    case Stopped = 'stopped';
    case Exhausted = 'exhausted';
}
