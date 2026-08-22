<?php

declare(strict_types=1);

namespace App\Dms\Dto;

/**
 * Contrat d'entrée de `POST /documents/{documentId}/public-links` (corps JSON, RG-DMS-05/06).
 * `versionId` : UUID de la version à épingler, `null` = `currentVersion` au moment de l'émission.
 * `expiresInDays` : 1..30, défaut 7 (arbitrage D18 pt.5).
 */
final readonly class IssuePublicLinkInput
{
    public function __construct(
        public ?string $versionId,
        public int $expiresInDays,
    ) {
    }
}
