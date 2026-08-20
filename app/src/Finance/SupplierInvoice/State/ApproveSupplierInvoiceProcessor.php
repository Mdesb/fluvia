<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Service\SupplierInvoiceApprovalHandler;
use App\Securite\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * POST /finance/supplier-invoices/{id}/approve — `read: true` : `$data` déjà résolu et filtré par
 * `PerimetreFinanceExtension` via le provider d'item standard (§0.2 point 1 du plan).
 *
 * @implements ProcessorInterface<SupplierInvoice, SupplierInvoice>
 */
final class ApproveSupplierInvoiceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SupplierInvoiceApprovalHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierInvoice
    {
        \assert($data instanceof SupplierInvoice);
        $acteur = $this->security->getUser();

        return $this->handler->approuver($data, $acteur instanceof Utilisateur ? $acteur : null);
    }
}
