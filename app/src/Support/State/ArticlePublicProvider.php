<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Support\Entity\ArticleAide;
use App\Support\Service\EtablissementContexteResolver;
use App\Support\Service\VisibiliteArticleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GET /support/articles/publics (US-SUP-01, RG-SUP-06, CA-1/CA-3) : navigation KB publique, filtres
 * `categorie`/`publicCible`/`moduleLie`/`etablissement`, `statut` **jamais** exposé (toujours forcé à
 * `publie` par `VisibiliteArticleService`, jamais un filtre client). Tri : local en tête (§0 décision
 * n°4 du plan) puis date de dernière modification.
 *
 * @implements ProviderInterface<list<ArticleAide>>
 */
final class ArticlePublicProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly VisibiliteArticleService $visibilite,
        private readonly EtablissementContexteResolver $etablissementResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $qb = $this->em->getRepository(ArticleAide::class)->createQueryBuilder('a');

        $this->visibilite->appliquerFiltres($qb, 'a', $this->etablissementResolver->resoudre());

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $categorie = $request->query->get('categorie');
            if (\is_string($categorie) && $categorie !== '') {
                $qb->andWhere('a.categorie = :categorie')->setParameter('categorie', basename($categorie), 'uuid');
            }
            $publicCible = $request->query->get('publicCible');
            if (\is_string($publicCible) && $publicCible !== '') {
                $qb->andWhere('a.publicCible = :publicCible')->setParameter('publicCible', $publicCible);
            }
            $moduleLie = $request->query->get('moduleLie');
            if (\is_string($moduleLie) && $moduleLie !== '') {
                $qb->andWhere('a.moduleLie LIKE :moduleLie')->setParameter('moduleLie', '%' . $moduleLie . '%');
            }
        }

        $qb->orderBy("CASE WHEN a.portee = 'local' THEN 0 ELSE 1 END", 'ASC')
            ->addOrderBy('a.dateDerniereModification', 'DESC');

        /** @var list<ArticleAide> */
        return $qb->getQuery()->getResult();
    }
}
