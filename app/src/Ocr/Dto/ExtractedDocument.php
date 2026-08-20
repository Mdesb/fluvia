<?php

declare(strict_types=1);

namespace App\Ocr\Dto;

use App\Ocr\Enum\ExtractionStatus;

/**
 * DTO de sortie du contrat `DocumentExtractor` (RG-OCR-01.1) — tous les champs métier sont optionnels
 * (une extraction partielle reste un succès partiel), seuls `status` et `provider` sont requis.
 * `withStatus()` permet à `App\Ocr\Service\TenantAwareDocumentExtractor` de rétrograder un `success` en
 * `low_confidence` (RG-OCR-05, plan-ocr.md §0.2 point 4) sans connaître la structure interne du DTO.
 */
final readonly class ExtractedDocument
{
    public function __construct(
        public ExtractionStatus $status,
        public string $provider,
        public ?string $supplierName = null,
        public ?string $documentNumber = null,
        public ?\DateTimeImmutable $documentDate = null,
        public ?string $amountExclTax = null,
        public ?string $amountInclTax = null,
        public ?string $vatAmount = null,
        public ?string $vatRate = null,
        public ?float $confidenceScore = null,
        public ?string $rawText = null,
    ) {
    }

    public function withStatus(ExtractionStatus $status): self
    {
        return new self(
            status: $status,
            provider: $this->provider,
            supplierName: $this->supplierName,
            documentNumber: $this->documentNumber,
            documentDate: $this->documentDate,
            amountExclTax: $this->amountExclTax,
            amountInclTax: $this->amountInclTax,
            vatAmount: $this->vatAmount,
            vatRate: $this->vatRate,
            confidenceScore: $this->confidenceScore,
            rawText: $this->rawText,
        );
    }
}
