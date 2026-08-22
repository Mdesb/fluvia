<?php

declare(strict_types=1);

namespace App\Dms\Dto;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Contrat d'entrée de `POST /documents/{id}/replace-version` (multipart, fichier seul). */
final readonly class DocumentReplaceInput
{
    public function __construct(
        public UploadedFile $file,
    ) {
    }
}
