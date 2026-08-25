<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Enum;

/** Canal d'envoi d'une `RecoveryAttempt` — e-mail seul en v1 (§9 spec, SMS hors périmètre RR-0). */
enum RecoveryChannel: string
{
    case Email = 'email';
}
