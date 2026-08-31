<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Marketing\Service\LoyaltyLedger;
use App\Marketing\Service\MarketingContext;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `GET /marketing/fidelite/{id}` — solde, palier, historique. Tout calculé, rien stocké.
 *
 * `{id}` désigne le CLIENT, pas une écriture de fidélité : c'est la seule variable d'URI qu'API
 * Platform résout sans mappage vers une propriété, et ce provider lit l'identifiant lui-même sans
 * aucune lecture implicite.
 *
 * Les deux gardes — droit sur l'établissement actif, client dans le périmètre — vivent dans
 * `MarketingContext`, écrites une seule fois pour les six points d'entrée du module.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final readonly class LoyaltyProvider implements ProviderInterface
{
    public function __construct(
        private MarketingContext $contexte,
        private LoyaltyLedger $registre,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        [$utilisateur, $etablissement] = $this->contexte->exigerAutorite('fidelite', 'lire');
        $client = $this->contexte->exigerClientDansLePerimetre(
            (string) ($uriVariables['id'] ?? ''),
            $utilisateur,
        );

        return new JsonResponse($this->registre->etatDe($client->getId(), $etablissement));
    }
}
