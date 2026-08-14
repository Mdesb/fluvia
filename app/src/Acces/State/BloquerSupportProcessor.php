<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\DeclarationPerteVol;
use App\Acces\Entity\Support;
use App\Acces\Service\BlocageSupportHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Déclaration de perte/vol (POST /acces/supports/{id}/bloquer, US-L3-09, CA-10) : blocage serveur
 * immédiat + révocation. Corps : { "motif": "…" }. Renvoie un JSON manuel (le type de sortie —
 * DeclarationPerteVol — diffère du type de la ressource hôte — Support —, même pattern que
 * `AnnulerVenteProcessor` en M2).
 *
 * @implements ProcessorInterface<Support, JsonResponse>
 */
final class BloquerSupportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly BlocageSupportHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Support);

        $agent = $this->security->getUser();
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent authentifié requis.');
        }

        $motif = (string) ($this->lecteur->corps()['motif'] ?? '');

        $declaration = $this->handler->bloquer($data, $motif, $agent);

        return $this->reponse($declaration);
    }

    private function reponse(DeclarationPerteVol $declaration): JsonResponse
    {
        return new JsonResponse([
            'id' => (string) $declaration->getId(),
            'support' => (string) $declaration->getSupport()?->getId(),
            'motif' => $declaration->getMotif(),
            'agent' => (string) $declaration->getAgent()?->getId(),
            'horodatage' => $declaration->getHorodatage()->format(DATE_ATOM),
            'annulee' => $declaration->isAnnulee(),
        ], JsonResponse::HTTP_CREATED);
    }
}
