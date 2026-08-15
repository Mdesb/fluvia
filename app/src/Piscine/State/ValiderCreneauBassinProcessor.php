<?php

declare(strict_types=1);

namespace App\Piscine\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Piscine\Entity\CreneauBassin;
use App\Piscine\Service\ValiderCreneauBassinHandler;

/**
 * POST /piscine/creneaux-bassin/{id}/valider (CA-4, US-L6-04).
 *
 * @implements ProcessorInterface<CreneauBassin, CreneauBassin>
 */
final class ValiderCreneauBassinProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ValiderCreneauBassinHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CreneauBassin
    {
        \assert($data instanceof CreneauBassin);

        return $this->handler->valider($data);
    }
}
