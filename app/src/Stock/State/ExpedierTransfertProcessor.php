<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stock\Entity\TransfertStock;
use App\Stock\Service\TransfertStockHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /stock/transferts/{id}/expedier` (RG-STOCK-14, CA-12, côté établissement source).
 *
 * @implements ProcessorInterface<mixed, TransfertStock>
 */
final class ExpedierTransfertProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransfertStockHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TransfertStock
    {
        \assert($data instanceof TransfertStock);

        $this->handler->expedier($data);
        $this->em->flush();

        return $data;
    }
}
