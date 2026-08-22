<?php

declare(strict_types=1);

namespace App\Dms\Enum;

/**
 * Catégorie d'un `Document` (spec-dms.md §5, RG-DMS-11) — catalogue fixe v1, codé en anglais (D5),
 * pas un référentiel configurable par établissement (arbitrage D18 pt.7). Associée à une politique de
 * rétention par défaut via `RetentionPolicy.defaultForCategory` (au plus une politique par catégorie).
 */
enum DocumentCategory: string
{
    case AccountingPiece = 'accounting_piece';
    case ExpenseReceipt = 'expense_receipt';
    case Contract = 'contract';
    case InterventionReport = 'intervention_report';
    case HrDocument = 'hr_document';
    case QuoteAttachment = 'quote_attachment';
    case MarketingAsset = 'marketing_asset';
    case Other = 'other';
}
