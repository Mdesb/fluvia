<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stock\Entity\TransfertStock;
use App\Stock\Service\TransfertStockHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /stock/transferts/{id}/recevoir` (RG-STOCK-14, CA-12, côté établissement destination).
 *
 * @implements ProcessorInterface<mixed, TransfertStock>
 */
final class RecevoirTransfertProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransfertStockHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TransfertStock
    {
        \assert($data instanceof TransfertStock);

        $this->handler->recevoir($data);
        $this->em->flush();

        return $data;
    }
}
