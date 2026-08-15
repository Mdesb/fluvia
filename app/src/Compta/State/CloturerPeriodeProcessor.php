<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Service\ClotureHandler;

/**
 * POST /compta/periodes/{id}/cloturer (RG-CLOTURE-10, CA-14) : refuse si points bloquants, sinon fige
 * la période et produit l'état récapitulatif.
 *
 * @implements ProcessorInterface<PeriodeComptable, PeriodeComptable>
 */
final class CloturerPeriodeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ClotureHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PeriodeComptable
    {
        \assert($data instanceof PeriodeComptable);

        return $this->handler->cloturer($data);
    }
}
