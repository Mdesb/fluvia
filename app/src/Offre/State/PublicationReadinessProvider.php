<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Offre\Entity\Produit;
use App\Offre\Service\PublicationGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /produits/{id}/readiness : ce qui manque pour publier, dit par la garde elle-même.
 *
 * La fiche produit en fait l'encadré « Pour mettre en vente, il manque : … ». Elle ne recalcule
 * rien : une seconde implémentation de la règle côté écran divergerait au premier correctif.
 *
 * Le produit passe par le fournisseur Doctrine standard, donc par `PerimetreProduitExtension` :
 * le produit d'un autre établissement répond 404, comme sa fiche.
 *
 * Réponse JSON brute (`{missing: [{code, message}]}`), même patron que `CreneauxProduitProvider`.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class PublicationReadinessProvider implements ProviderInterface
{
    /** @param ProviderInterface<Produit> $item */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private readonly ProviderInterface $item,
        private readonly PublicationGuard $guard,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $product = $this->item->provide($operation, $uriVariables, $context);
        if (!$product instanceof Produit) {
            throw new NotFoundHttpException('Produit introuvable.');
        }

        $missing = [];
        foreach ($this->guard->missing($product) as $code => $message) {
            $missing[] = ['code' => $code, 'message' => $message];
        }

        return new JsonResponse(['missing' => $missing]);
    }
}
