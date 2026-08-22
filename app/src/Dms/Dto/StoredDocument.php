<?php

declare(strict_types=1);

namespace App\Dms\Dto;

use Symfony\Component\Uid\Uuid;

/** Métadonnées d'un `Document` retournées au module consommateur (port `DocumentStore`). */
final readonly class StoredDocument
{
    public function __construct(
        public Uuid $documentId,
        public Uuid $currentVersionId,
    ) {
    }
}
