<?php

declare(strict_types=1);

namespace App\Piscine\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Piscine\Entity\Casier;
use App\Piscine\Service\ForcerCasierHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /piscine/casiers/{id}/forcer (US-L6-09, CA-9, décision actée). Corps : { "motif": string }.
 * Renvoie un JSON manuel (type de sortie — ForcageCasier — différent de la ressource hôte — Casier).
 *
 * @implements ProcessorInterface<Casier, JsonResponse>
 */
final class ForcerCasierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ForcerCasierHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Casier);

        $agent = $this->security->getUser();
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent authentifié requis.');
        }

        $motif = (string) ($this->lecteur->corps()['motif'] ?? '');

        $forcage = $this->handler->forcer($data, $agent, $motif);

        return new JsonResponse([
            'id' => (string) $forcage->getId(),
            'casier' => (string) $forcage->getCasier()?->getId(),
            'agent' => (string) $forcage->getAgent()?->getId(),
            'motif' => $forcage->getMotif(),
            'horodatage' => $forcage->getHorodatage()->format(DATE_ATOM),
        ], JsonResponse::HTTP_CREATED);
    }
}
