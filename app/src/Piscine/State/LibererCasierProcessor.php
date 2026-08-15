<?php

declare(strict_types=1);

namespace App\Piscine\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Piscine\Entity\Casier;
use App\Piscine\Service\LibererCasierHandler;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * POST /piscine/casiers/{id}/liberer (US-L6-09, CA-9). Renvoie un JSON manuel (type de sortie —
 * CautionCasier — différent de la ressource hôte — Casier —, même pattern que
 * `AttribuerCasierProcessor`).
 *
 * @implements ProcessorInterface<Casier, JsonResponse>
 */
final class LibererCasierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LibererCasierHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Casier);

        $caution = $this->handler->liberer($data);

        return new JsonResponse([
            'id' => (string) $caution->getId(),
            'casier' => (string) $caution->getCasier()?->getId(),
            'montant' => $caution->getMontant(),
            'statut' => $caution->getStatut()->value,
            'dateLiberation' => $caution->getDateLiberation()?->format(DATE_ATOM),
        ]);
    }
}
