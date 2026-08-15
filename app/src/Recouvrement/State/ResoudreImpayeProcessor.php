<?php

declare(strict_types=1);

namespace App\Recouvrement\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Service\ResolutionImpayeHandler;

/**
 * POST /recouvrement/incidents/{id}/resoudre. Résolution 1 clic (CB) : restaure automatiquement
 * l'accès dès l'encaissement confirmé, sans intervention d'un agent.
 *
 * @implements ProcessorInterface<IncidentImpaye, IncidentImpaye>
 */
final class ResoudreImpayeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ResolutionImpayeHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IncidentImpaye
    {
        \assert($data instanceof IncidentImpaye);

        return $this->handler->resoudre($data);
    }
}
