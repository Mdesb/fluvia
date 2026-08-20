<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * POST /finance/supplier-invoices/{id}/cancel — annulation, `draft` uniquement (RG-SINV-04), avant
 * toute comptabilisation.
 *
 * @implements ProcessorInterface<SupplierInvoice, SupplierInvoice>
 */
final class CancelSupplierInvoiceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierInvoice
    {
        \assert($data instanceof SupplierInvoice);

        if ($data->getStatus() !== SupplierInvoiceStatus::Draft) {
            throw new ConflictHttpException('Seule une facture en brouillon peut être annulée (RG-SINV-04).');
        }

        $data->setStatus(SupplierInvoiceStatus::Cancelled);
        $this->em->flush();

        return $data;
    }
}
