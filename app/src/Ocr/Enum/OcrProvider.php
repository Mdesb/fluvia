<?php

declare(strict_types=1);

namespace App\Ocr\Enum;

/**
 * Fournisseur d'extraction configuré par établissement (RG-OCR-06, spec-ocr.md §4.5). Liste **fermée**
 * volontairement (plan-ocr.md §1ter) : ce ne sont pas des catégories métier paramétrables mais des
 * implémentations logicielles enregistrées — ajouter un fournisseur = ajouter un cas d'enum + un
 * adaptateur (`DocumentExtractor`), acte de développement.
 */
enum OcrProvider: string
{
    case Manual = 'manual';
    case Anthropic = 'anthropic';
}
