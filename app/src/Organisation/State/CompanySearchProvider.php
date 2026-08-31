<?php

declare(strict_types=1);

namespace App\Organisation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organisation\Service\CompanyDirectory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * `GET /organisation/entreprises?q=…` — ce que l'annuaire officiel connaît de ce nom.
 *
 * Aucune écriture, aucune trace : la frappe de l'exploitant sert à chercher, pas à enregistrer.
 *
 * L'appel part du serveur et non du navigateur — voir `CompanyDirectory` pour les trois raisons.
 * L'indisponibilité de l'annuaire est rendue comme telle (`disponible: false`) et non comme une
 * absence de résultat : « je n'ai rien trouvé » et « je n'ai pas pu chercher » appellent deux gestes
 * différents.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final readonly class CompanySearchProvider implements ProviderInterface
{
    public function __construct(
        private CompanyDirectory $annuaire,
        private RequestStack $requetes,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $terme = (string) ($this->requetes->getCurrentRequest()?->query->get('q') ?? '');

        return new JsonResponse($this->annuaire->chercher($terme));
    }
}
