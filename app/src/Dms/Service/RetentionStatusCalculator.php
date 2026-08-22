<?php

declare(strict_types=1);

namespace App\Dms\Service;

use App\Dms\Enum\RetentionStatus;

/**
 * Dérive le statut de rétention (RG-DMS-12) à partir de `Document.retainUntil` — pure, sans effet de
 * bord, comparaison en dates civiles (pas d'heure) : `retainUntil` est un `date_immutable`, jamais un
 * horodatage. Utilisé par l'API (champ calculé `Document::getRetentionStatus()`),
 * `DeleteDocumentProcessor` et `PurgeDocumentsCommand`.
 */
final class RetentionStatusCalculator
{
    public function statusFor(?\DateTimeImmutable $retainUntil): RetentionStatus
    {
        if ($retainUntil === null) {
            return RetentionStatus::None;
        }

        $aujourdHui = new \DateTimeImmutable('today');

        return $retainUntil > $aujourdHui ? RetentionStatus::Active : RetentionStatus::Expired;
    }
}
