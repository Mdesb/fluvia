<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Offre\Doctrine\ProductScope;
use App\Offre\Entity\ProductPhoto;
use App\Offre\Entity\Produit;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /offre/produits/{id}/photos` — les photos d'un produit, dans leur ordre.
 *
 * `{id}` désigne le PRODUIT et non la photo : c'est la seule variable d'URI qu'API Platform résout
 * sans mappage, et ce provider lit l'identifiant lui-même.
 *
 * @cloisonnement-verifie : le produit est résolu par `ProductScope::restreindre()` — la clause que
 * `PerimetreProduitExtension` applique déjà aux collections du module (socle + établissement actif,
 * D51). Ce n'est pas `codesEffectifs()` parce qu'un produit du socle n'appartient à AUCUN
 * établissement : recalculer un droit contre un établissement inexistant refuserait précisément les
 * produits partagés. Le refus est un 404. — claude-A, 28/08
 *
 * @implements ProviderInterface<JsonResponse>
 */
final readonly class ProductPhotoListProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $uriVariables['id'] ?? null;
        if ($id === null) {
            throw new NotFoundHttpException('Produit introuvable.');
        }

        $produit = $this->produitVisible($id);

        /** @var list<ProductPhoto> $photos */
        $photos = $this->entityManager->getRepository(ProductPhoto::class)->createQueryBuilder('ph')
            // ⚠ D58 — l'entité liée sans son type ne trouverait rien, et l'écran dirait
            // « aucune photo » à un produit qui en a dix (garde-fou n°16).
            ->andWhere('IDENTITY(ph.produit) = :produit')
            ->setParameter('produit', $produit->getId(), 'uuid')
            ->orderBy('ph.position', 'ASC')
            ->addOrderBy('ph.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return new JsonResponse([
            'produit' => (string) $produit->getId(),
            'photos' => array_map(static fn (ProductPhoto $photo): array => [
                'id' => (string) $photo->getId(),
                'url' => $photo->getUrl(),
                'altText' => $photo->getAltText(),
                'position' => $photo->getPosition(),
            ], $photos),
            // Dit à l'écran, pas seulement au code : la photo ne s'affichera en boutique que si le
            // produit y est réellement vendu. Sinon l'exploitant croit à une panne du téléversement.
            'visiblePubliquement' => $produit->getStatut()->value === 'publie'
                && \in_array('en_ligne', $produit->getCanaux(), true),
        ]);
    }

    private function produitVisible(mixed $id): Produit
    {
        $qb = $this->entityManager->getRepository(Produit::class)->createQueryBuilder('p');
        ProductScope::restreindre($qb, 'p', $this->contexte->idActif());

        $produit = $qb->andWhere('p.id = :produit')
            ->setParameter('produit', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        if (!$produit instanceof Produit) {
            throw new NotFoundHttpException('Produit introuvable.');
        }

        return $produit;
    }
}
