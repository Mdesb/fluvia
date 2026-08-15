<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Motif métier Sport de dévalidation du droit d'accès — non porté par L3 (§1.6 du plan). */
enum MotifInactiviteAccesFitness: string
{
    case Impaye = 'impaye';
    case Pause = 'pause';
    case Resiliation = 'resiliation';
}
