<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stock\Entity\ReceptionAchat;
use App\Stock\Service\ReceptionAchatValidationHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /stock/receptions-achat/{id}/valider` (RG-STOCK-05, CA-5).
 *
 * @implements ProcessorInterface<mixed, ReceptionAchat>
 */
final class ValiderReceptionAchatProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReceptionAchatValidationHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReceptionAchat
    {
        \assert($data instanceof ReceptionAchat);

        $this->handler->valider($data);
        $this->em->flush();

        return $data;
    }
}
