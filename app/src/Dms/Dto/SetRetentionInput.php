<?php

declare(strict_types=1);

namespace App\Dms\Dto;

/**
 * Contrat d'entrée de `POST /documents/{id}/retention` (corps JSON, RG-DMS-16). `null` + `null` = lever
 * la politique (cas exceptionnel encadré, RG-DMS-16). `retainUntilOverride` : date ISO (`Y-m-d`).
 */
final readonly class SetRetentionInput
{
    public function __construct(
        public ?string $retentionPolicyCode,
        public ?string $retainUntilOverride,
    ) {
    }
}
