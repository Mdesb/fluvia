<?php

declare(strict_types=1);

namespace App\PublicApi\Controller;

use App\PublicApi\Enum\ApiScope;
use App\PublicApi\Read\AccessEventProvider;
use App\PublicApi\Read\AccessRightProvider;
use App\PublicApi\Read\PartnerReadQuery;
use App\PublicApi\Security\PartnerUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les ressources `/v1` de la portée `access:read` (spec API partenaire v1, §3.2).
 *
 * ⚠ **AUCUNE ENTITÉ INTERNE N'EST SÉRIALISÉE ICI.** Chaque ressource a son provider dédié, qui compose
 * un tableau de contrat champ par champ : un champ ajouté à `Support` ou `Passage` demain n'apparaît
 * pas chez le partenaire sans qu'on l'ait écrit ici. Le périmètre vient de `PartnerReadQuery`.
 */
final class AccessReadController extends AbstractController
{
    #[Route('/v1/access-rights', name: 'public_api_access_rights', methods: ['GET'])]
    public function rights(Request $request, AccessRightProvider $provider): JsonResponse
    {
        return $this->serve($request, static fn (PartnerReadQuery $q, PartnerUser $p): array => $provider->page($q, $p));
    }

    #[Route('/v1/access-events', name: 'public_api_access_events', methods: ['GET'])]
    public function events(Request $request, AccessEventProvider $provider): JsonResponse
    {
        return $this->serve($request, static fn (PartnerReadQuery $q, PartnerUser $p): array => $provider->page($q, $p));
    }

    /** @param callable(PartnerReadQuery, PartnerUser): array<string, mixed> $page */
    private function serve(Request $request, callable $page): JsonResponse
    {
        $partner = $this->getUser();
        if (!$partner instanceof PartnerUser) {
            return new JsonResponse(['message' => 'Clé d\'API requise.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            return new JsonResponse($page(PartnerReadQuery::fromRequest($request, $partner, ApiScope::AccessRead), $partner));
        } catch (HttpExceptionInterface $e) {
            return new JsonResponse(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }
}
