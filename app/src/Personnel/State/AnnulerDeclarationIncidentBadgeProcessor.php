<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\DeclarationPerteVol;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Service\RevocationBadgeHandler;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
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
        private readonly CalculateurDroits $calculateur,
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

        // Cloisonnement (D3/D8) — l'opération est `read: false` : la déclaration est résolue depuis
        // l'identifiant de l'URI par un `find()` direct, hors des extensions. On recalcule l'autorité de
        // l'agent contre l'établissement du BADGE VISÉ (pas l'en-tête X-Etablissement, D6) : sans quoi on
        // réactive un badge d'un autre établissement. Échec fermé en 404 (anti-oracle).
        //
        // @cloisonnement-verifie : la confrontation porte sur `$badge->getEtablissement()` et non sur
        // `$declaration` — `DeclarationPerteVol` n'a pas d'établissement propre, il est porté par le
        // support/badge (1:1 via `support`). Confronter l'établissement du badge cloisonne donc bien la
        // déclaration résolue depuis l'URI. — claude-B, 26/08.
        $codes = $this->calculateur->codesEffectifs($agent, $badge->getEtablissement()?->getId());
        if (!$this->calculateur->autorise($codes, 'personnel', 'gerer_badge')
            && !$this->calculateur->autorise($codes, 'acces', 'bloquer_support')) {
            throw new NotFoundHttpException('Déclaration introuvable.');
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
