<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\DeclarationPerteVol;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Service\RevocationBadgeHandler;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Annule une déclaration d'incident badge (POST /personnel/declarations-incident/{id}/annuler,
 * RG-PERSO-08) : délègue à `BlocageSupportHandler::annuler()` (via `RevocationBadgeHandler::reactiver()`),
 * réversible par un rôle habilité (RG-ACC-07).
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class AnnulerDeclarationIncidentBadgeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RevocationBadgeHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $agent = $this->security->getUser();
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent authentifié requis.');
        }

        $id = $this->uuid($uriVariables['id'] ?? null);
        if ($id === null) {
            throw new NotFoundHttpException('Déclaration introuvable.');
        }

        $declaration = $this->em->getRepository(DeclarationPerteVol::class)->find($id);
        if (!$declaration instanceof DeclarationPerteVol) {
            throw new NotFoundHttpException('Déclaration introuvable.');
        }

        $badge = $this->em->getRepository(BadgeStaff::class)->findOneBy(['support' => $declaration->getSupport()]);
        if (!$badge instanceof BadgeStaff) {
            throw new NotFoundHttpException('Badge staff introuvable pour cette déclaration.');
        }

        $this->handler->reactiver($badge, $agent);

        return new JsonResponse([
            'id' => (string) $declaration->getId(),
            'badgeStaff' => (string) $badge->getId(),
            'annulee' => true,
        ]);
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if ($reference instanceof Uuid) {
            return $reference;
        }
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
