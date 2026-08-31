<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Marketing\Service\MarketingContext;
use App\Marketing\Service\ReferralService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /marketing/parrainage/code/{id}` — le code d'un client, créé au premier appel.
 *
 * `{id}` désigne le CLIENT. Demander son code EST le geste qui l'attribue : un écran qui afficherait
 * « aucun code » sans rien pour en obtenir un serait une impasse, et l'agent au comptoir a le client
 * devant lui.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final readonly class ReferralCodeProvider implements ProviderInterface
{
    public function __construct(
        private MarketingContext $contexte,
        private ReferralService $parrainage,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        [$utilisateur, $etablissement] = $this->contexte->exigerAutorite('fidelite', 'lire');
        $client = $this->contexte->exigerClientDansLePerimetre(
            (string) ($uriVariables['id'] ?? ''),
            $utilisateur,
        );

        $code = $this->parrainage->codeDe($client->getId(), $etablissement);

        return new JsonResponse([
            'client' => (string) $client->getId(),
            'code' => $code->getCode(),
            'depuis' => $code->getCreatedAt()->format(\DATE_ATOM),
        ]);
    }
}
