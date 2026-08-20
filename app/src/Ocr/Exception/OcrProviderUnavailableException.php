<?php

declare(strict_types=1);

namespace App\Ocr\Exception;

/**
 * Seul cas d'exception légitime levé par un adaptateur `DocumentExtractor` (RG-OCR-01 docblock,
 * plan-ocr.md §0.5) : échec de transport HTTP (timeout, DNS, 5xx du fournisseur) — **jamais** pour un
 * document illisible ou une réponse non conforme (ces cas retournent respectivement `status: failed`/
 * `status: low_confidence`, RG-OCR-03). Capturée systématiquement par
 * `App\Ocr\Service\TenantAwareDocumentExtractor` (défense en profondeur : tout `\Throwable`).
 */
final class OcrProviderUnavailableException extends \RuntimeException
{
}
