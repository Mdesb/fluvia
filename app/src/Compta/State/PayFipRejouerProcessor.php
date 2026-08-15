<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\BordereauPayFiP;
use App\Compta\Service\TraiterRetourPayFipHandler;

/**
 * POST /compta/payfip/{id}/rejouer (RG-PAYFIP-03) : rejeu d'une transaction en retour manquant.
 *
 * @implements ProcessorInterface<BordereauPayFiP, BordereauPayFiP>
 */
final class PayFipRejouerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TraiterRetourPayFipHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BordereauPayFiP
    {
        \assert($data instanceof BordereauPayFiP);

        return $this->handler->rejouer($data);
    }
}
