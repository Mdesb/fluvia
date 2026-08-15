<?php

declare(strict_types=1);

namespace App\Piscine\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Piscine\Entity\CreneauPublic;
use App\Piscine\Service\CreneauPublicHandler;

/**
 * DELETE d'un `CreneauPublic` : libère les lignes qui ne sont plus utilisées et recalcule la jauge
 * grand public (US-L6-07).
 *
 * @implements ProcessorInterface<CreneauPublic, void>
 */
final class CreneauPublicRemoveProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CreneauPublicHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        \assert($data instanceof CreneauPublic);

        $this->handler->retirer($data);
    }
}
