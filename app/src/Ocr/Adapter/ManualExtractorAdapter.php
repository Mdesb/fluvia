<?php

declare(strict_types=1);

namespace App\Ocr\Adapter;

use App\Ocr\DocumentExtractor;
use App\Ocr\Dto\DocumentToExtract;
use App\Ocr\Dto\ExtractedDocument;
use App\Ocr\Enum\DocumentKind;
use App\Ocr\Enum\ExtractionStatus;
use App\Ocr\Enum\OcrProvider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Mode dégradé — **toujours disponible** (US-OCR-02, RG-OCR-02) : retourne systématiquement
 * `status: failed`/`provider: manual`, tous les autres champs `null`, **jamais d'exception**,
 * **jamais de latence réseau**. Implémentation par défaut quand aucun fournisseur réel n'est
 * configuré pour l'établissement (RG-OCR-06), et repli automatique si le fournisseur réel échoue
 * (RG-OCR-04, orchestré par `App\Ocr\Service\TenantAwareDocumentExtractor`).
 *
 * Taggée `ocr.extractor` (registre `App\Ocr\Service\DocumentExtractorRegistry`, même patron que
 * `App\Compta\Export\ExportComptableResolver`) : seul adaptateur statique du registre —
 * `AnthropicDocumentExtractorAdapter` n'y figure pas (une instance par tenant, produite à la demande
 * par `AnthropicDocumentExtractorAdapterFactory`, plan-ocr.md §0.3).
 */
#[AutoconfigureTag('ocr.extractor')]
final class ManualExtractorAdapter implements DocumentExtractor
{
    public function provider(): string
    {
        return OcrProvider::Manual->value;
    }

    public function extract(DocumentToExtract $document, DocumentKind $kind): ExtractedDocument
    {
        return new ExtractedDocument(status: ExtractionStatus::Failed, provider: $this->provider());
    }
}
