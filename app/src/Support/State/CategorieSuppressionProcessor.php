<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Support\Entity\ArticleAide;
use App\Support\Entity\CategorieAide;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * POST /support/categories/{id}/supprimer (RG-SUP-01, cas limite §8 spec) : refuse (409) si la
 * catégorie contient au moins un `ArticleAide` ou une sous-catégorie.
 *
 * @implements ProcessorInterface<CategorieAide, JsonResponse>
 */
final class CategorieSuppressionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof CategorieAide);

        $nbArticles = (int) $this->em->getRepository(ArticleAide::class)->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.categorie = :categorie')
            ->setParameter('categorie', $data->getId(), 'uuid')
            ->getQuery()->getSingleScalarResult();

        $nbSousCategories = (int) $this->em->getRepository(CategorieAide::class)->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.parent = :parent')
            ->setParameter('parent', $data->getId(), 'uuid')
            ->getQuery()->getSingleScalarResult();

        if ($nbArticles > 0 || $nbSousCategories > 0) {
            throw new ConflictHttpException('Catégorie non vide : suppression refusée.');
        }

        $this->em->remove($data);
        $this->em->flush();

        return new JsonResponse(['supprime' => true], 200);
    }
}
