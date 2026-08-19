<?php

declare(strict_types=1);

namespace App\Ocr\Enum;

/**
 * Type de document soumis à extraction (RG-OCR-01, spec-ocr.md §2/§4.1) — seul indice métier transmis
 * au service transverse `App\Ocr` : jamais de référence à une entité `SupplierInvoice`/`ExpenseLine`,
 * le contrat reste générique (aucune dépendance de `App\Ocr` vers le domaine Finance).
 */
enum DocumentKind: string
{
    case SupplierInvoice = 'supplier_invoice';
    case ExpenseReceipt = 'expense_receipt';
}
