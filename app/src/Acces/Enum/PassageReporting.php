<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Comment les passages remontent (D17, axe 4) — ce qui conditionne la fraîcheur de la supervision. */
enum PassageReporting: string
{
    /** Remontée au fil de l'eau. */
    case RealTime = 'real_time';

    /** Remontée par lot, à la synchronisation — le journal du jour peut n'arriver que le lendemain. */
    case OnSync = 'on_sync';

    /** Aucune remontée : le matériel ne rend pas compte de ce qu'il a laissé passer. */
    case None = 'none';
}
