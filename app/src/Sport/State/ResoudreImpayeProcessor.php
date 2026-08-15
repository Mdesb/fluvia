<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Sport\Entity\IncidentPrelevement;
use App\Sport\Service\ResolutionImpayeHandler;

/**
 * POST /sport/impayes/{id}/resoudre (US-SPORT-07, RG-SPORT-03, CA-8). Résolution 1 clic (CB) :
 * restaure automatiquement l'accès dès l'encaissement confirmé, sans intervention d'un agent.
 *
 * @implements ProcessorInterface<IncidentPrelevement, IncidentPrelevement>
 */
final class ResoudreImpayeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ResolutionImpayeHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IncidentPrelevement
    {
        \assert($data instanceof IncidentPrelevement);

        return $this->handler->resoudre($data);
    }
}
