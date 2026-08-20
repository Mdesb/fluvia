<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Dto;

/**
 * Écart de rapprochement 3 voies (commande / réception / facture), calculé à la volée par
 * `PurchaseReconciliationCalculator` — **jamais persisté** (§4.3 spec-supplier-invoices.md, RG-SINV-03).
 * Un écart au-delà du seuil paramétrable (`ReconciliationSettings`) est signalé (`thresholdExceeded`)
 * mais ne bloque jamais la validation (CA-3).
 */
final readonly class PurchaseReconciliationGap
{
    public function __construct(
        public string $lineId,
        public string $quantityGap,
        public string $unitPriceGap,
        public string $unitPriceGapPercent,
        public bool $thresholdExceeded,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lineId' => $this->lineId,
            'quantityGap' => $this->quantityGap,
            'unitPriceGap' => $this->unitPriceGap,
            'unitPriceGapPercent' => $this->unitPriceGapPercent,
            'thresholdExceeded' => $this->thresholdExceeded,
        ];
    }
}
