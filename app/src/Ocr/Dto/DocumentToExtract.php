<?php

declare(strict_types=1);

namespace App\Ocr\Dto;

/**
 * DTO d'entrée du contrat `DocumentExtractor` (RG-OCR-01) — flux binaire du document (image/PDF) et
 * son type MIME. Non persisté : `App\Ocr` ne stocke jamais le contenu d'un document source, seule une
 * trace d'audit minimale (`App\Ocr\Entity\ExtractionAttempt`) est journalisée.
 */
final readonly class DocumentToExtract
{
    public function __construct(
        public string $content,
        public string $mimeType,
    ) {
    }
}
