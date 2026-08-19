<?php

declare(strict_types=1);

namespace App\Ocr\Enum;

/**
 * Statut d'une tentative d'extraction documentaire (RG-OCR-01.1, RG-OCR-04/05, spec-ocr.md §4.1/4.4).
 * `Failed` = aucune extraction exploitable (document illisible, fournisseur non configuré) ;
 * `LowConfidence` = extraction produite mais sous le seuil de confiance paramétrable (RG-OCR-05) ou
 * réponse fournisseur non strictement conforme (RG-OCR-03) ; `Success` = extraction fiable.
 */
enum ExtractionStatus: string
{
    case Success = 'success';
    case LowConfidence = 'low_confidence';
    case Failed = 'failed';
}
