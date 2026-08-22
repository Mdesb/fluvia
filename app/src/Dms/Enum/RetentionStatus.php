<?php

declare(strict_types=1);

namespace App\Dms\Enum;

/**
 * Statut de rétention **calculé**, jamais persisté (RG-DMS-12) — dérivé de `Document.retainUntil` par
 * `App\Dms\Service\RetentionStatusCalculator`. `None` : aucune politique attachée, suppression libre.
 * `Active` : `retainUntil` dans le futur, suppression refusée. `Expired` : `retainUntil` dépassé,
 * suppression autorisée (éligible à la purge après délai de grâce, RG-DMS-15).
 */
enum RetentionStatus: string
{
    case None = 'none';
    case Active = 'active';
    case Expired = 'expired';
}
