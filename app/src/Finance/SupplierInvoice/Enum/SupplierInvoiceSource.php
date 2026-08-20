<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Enum;

/**
 * Origine de la saisie (RG-SINV-02) — dérivée serveur de la présence d'`ocrExtraction`, jamais
 * déclarée par le client (§1 du plan).
 */
enum SupplierInvoiceSource: string
{
    case Manual = 'manual';
    case Ocr = 'ocr';
}
