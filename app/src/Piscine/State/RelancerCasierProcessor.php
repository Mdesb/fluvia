<?php

declare(strict_types=1);

namespace App\Piscine\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Piscine\Entity\Casier;
use App\Piscine\Service\RelancerCasierHandler;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * POST /piscine/casiers/{id}/relancer (US-L6-09, CA-9). Renvoie un JSON manuel (type de sortie —
 * RelanceCasier — différent de la ressource hôte — Casier).
 *
 * @implements ProcessorInterface<Casier, JsonResponse>
 */
final class RelancerCasierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RelancerCasierHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Casier);

        $relance = $this->handler->relancer($data);

        return new JsonResponse([
            'id' => (string) $relance->getId(),
            'casier' => (string) $relance->getCasier()?->getId(),
            'dateRelance' => $relance->getDateRelance()->format(DATE_ATOM),
            'delaiForcageJours' => $relance->getDelaiForcageJours(),
        ], JsonResponse::HTTP_CREATED);
    }
}
