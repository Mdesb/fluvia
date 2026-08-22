<?php

declare(strict_types=1);

namespace App\Dms\Dto;

use Symfony\Component\Uid\Uuid;

/** Métadonnées seules d'une `DocumentVersion` (port `DocumentStore::currentVersion()`), sans contenu. */
final readonly class StoredDocumentVersion
{
    public function __construct(
        public Uuid $versionId,
        public int $versionNumber,
        public string $fileHash,
        public int $sizeBytes,
        public string $mimeType,
    ) {
    }
}
