<?php

declare(strict_types=1);

namespace App\Musee\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Musee\Entity\VisiteGuidee;
use App\Musee\Service\ConfirmerVisiteGuideeHandler;

/**
 * POST /musee/visites-guidees/{id}/confirmer (CA-3/CA-4).
 *
 * @implements ProcessorInterface<VisiteGuidee, VisiteGuidee>
 */
final class ConfirmerVisiteGuideeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ConfirmerVisiteGuideeHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): VisiteGuidee
    {
        \assert($data instanceof VisiteGuidee);

        return $this->handler->confirmer($data);
    }
}
