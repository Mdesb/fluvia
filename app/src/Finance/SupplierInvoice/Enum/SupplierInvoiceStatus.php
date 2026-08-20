<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Enum;

/**
 * Cycle de vie d'une facture fournisseur (RG-SINV-04) : `draft -> to_pay -> (partially_paid) -> paid`,
 * ou `disputed` (depuis `to_pay`/`partially_paid`), ou `cancelled` (depuis `draft` uniquement, avant
 * toute comptabilisation). Valeurs anglaises (D5).
 */
enum SupplierInvoiceStatus: string
{
    case Draft = 'draft';
    case ToPay = 'to_pay';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';
}
