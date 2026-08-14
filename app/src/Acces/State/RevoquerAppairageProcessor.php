<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\Appairage;
use App\Acces\Service\AppairageHandler;

/**
 * Révoque l'appairage actif (POST /acces/appairages/{id}/revoquer, US-L3-02) : libère le support pour
 * un ré-appairage ultérieur (CA-2).
 *
 * @implements ProcessorInterface<Appairage, Appairage>
 */
final class RevoquerAppairageProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AppairageHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Appairage
    {
        \assert($data instanceof Appairage);

        return $this->handler->revoquer($data);
    }
}
