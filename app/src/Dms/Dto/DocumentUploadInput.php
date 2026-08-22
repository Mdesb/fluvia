<?php

declare(strict_types=1);

namespace App\Dms\Dto;

use App\Dms\Enum\DocumentCategory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Contrat d'entrée de `POST /documents` (multipart, plan-dms.md §2.2). Construit manuellement par
 * `App\Dms\Processor\UploadDocumentProcessor` à partir du `Request` courant (`input: false` — même
 * idiome que `App\Vente\Service\LecteurCorps` pour le JSON, aucun précédent sur ce dépôt de DTO
 * auto-désérialisé par API Platform, y compris pour du JSON simple ; le contrat reste documenté ici,
 * pas fondu dans le processor).
 */
final readonly class DocumentUploadInput
{
    /** @param list<string>|null $tags */
    public function __construct(
        public DocumentCategory $category,
        public string $title,
        public ?array $tags,
        public ?string $sourceModule,
        public UploadedFile $file,
    ) {
    }
}
