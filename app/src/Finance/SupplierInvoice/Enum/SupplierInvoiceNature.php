<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Enum;

/** Nature d'un enregistrement `SupplierInvoice` (RG-SINV-09) : facture reçue, ou avoir fournisseur. */
enum SupplierInvoiceNature: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
}
