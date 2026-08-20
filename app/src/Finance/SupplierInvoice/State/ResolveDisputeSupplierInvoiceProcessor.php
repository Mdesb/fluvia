<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Service\SupplierInvoiceDisputeHandler;
use App\Vente\Service\LecteurCorps;

/**
 * POST /finance/supplier-invoices/{id}/resolve-dispute — corps `{ resolutionReason }` (§4.7 spec).
 *
 * @implements ProcessorInterface<SupplierInvoice, SupplierInvoice>
 */
final class ResolveDisputeSupplierInvoiceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SupplierInvoiceDisputeHandler $handler,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierInvoice
    {
        \assert($data instanceof SupplierInvoice);
        $corps = $this->lecteur->corps();
        $motif = $corps['resolutionReason'] ?? null;

        return $this->handler->resoudre($data, \is_string($motif) ? $motif : null);
    }
}
