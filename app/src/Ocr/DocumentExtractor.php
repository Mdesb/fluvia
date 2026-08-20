<?php

declare(strict_types=1);

namespace App\Ocr;

use App\Ocr\Dto\DocumentToExtract;
use App\Ocr\Dto\ExtractedDocument;
use App\Ocr\Enum\DocumentKind;

/**
 * Contrat littéral imposé par spec-ocr.md §4.1 — vit volontairement à la racine `App\Ocr` (pas de
 * sous-namespace), sans dépendance au domaine Finance (`App\Ocr` ne connaît ni `SupplierInvoice` ni
 * `ExpenseReport`). Aliasé en DI vers `App\Ocr\Service\TenantAwareDocumentExtractor`
 * (`#[AsAlias]`, plan-ocr.md §0.2) : tout consommateur qui type-hinte cette interface obtient la
 * sélection de provider par tenant + le seuil de confiance + le repli automatique en dégradé.
 */
interface DocumentExtractor
{
    /** Nom technique du fournisseur (ex. 'manual', 'anthropic') — pour la traçabilité (RG-OCR-04). */
    public function provider(): string;

    /**
     * Extrait les champs structurés d'un document financier. Ne lance jamais d'exception pour un
     * document illisible/non extractible : retourne un `ExtractedDocument` en statut `failed` ou
     * `low_confidence` (RG-OCR-04). Une exception ne peut provenir que d'une erreur d'infrastructure
     * (ex. fournisseur externe injoignable, `OcrProviderUnavailableException`) — auquel cas l'appelant
     * doit basculer en dégradé (§4.2 spec-ocr.md).
     */
    public function extract(DocumentToExtract $document, DocumentKind $kind): ExtractedDocument;
}
