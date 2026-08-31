<?php

declare(strict_types=1);

namespace App\Dms\Dto;

use App\Dms\Enum\DocumentCategory;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;

/**
 * Requête du port PHP `App\Dms\DocumentStore::store()` — porte **explicitement** `establishment`
 * (plan-dms.md §3.3/§5) : le module appelant (ex. Finance) connaît déjà son propre périmètre, jamais
 * `ContexteEtablissement` ici (différence délibérée avec la voie HTTP `UploadDocumentProcessor`).
 */
final readonly class StoreDocumentRequest
{
    /** @param resource $content */
    public function __construct(
        public Etablissement $establishment,
        public DocumentCategory $category,
        public string $title,
        public mixed $content,
        public string $originalFilename,
        public string $mimeType,
        public ?string $sourceModule = null,
        public ?Utilisateur $actor = null,
    ) {
    }
}
