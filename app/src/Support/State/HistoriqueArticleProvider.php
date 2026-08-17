<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Support\Entity\VersionArticle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GET /support/articles/{articleId}/historique (RG-SUP-03, CA-5) : toutes les `VersionArticle` d'un
 * article, la plus récente en premier — jamais exposé au public (§3 plan).
 *
 * @implements ProviderInterface<list<VersionArticle>>
 */
final class HistoriqueArticleProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $articleId = $this->resoudreArticleId($uriVariables);
        if ($articleId === null) {
            return [];
        }

        /** @var list<VersionArticle> */
        return $this->em->getRepository(VersionArticle::class)->createQueryBuilder('v')
            ->andWhere('v.article = :article')
            ->setParameter('article', $articleId, 'uuid')
            ->orderBy('v.numero', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @param array<string, mixed> $uriVariables */
    private function resoudreArticleId(array $uriVariables): ?string
    {
        foreach (['articleId', 'article_id', 'id'] as $cle) {
            $valeur = $uriVariables[$cle] ?? null;
            if (\is_string($valeur) && $valeur !== '') {
                return basename($valeur);
            }
        }

        // Filet de sécurité : extraction directe depuis le chemin de la requête (comportement
        // observé de `$uriVariables` non garanti pour une clé personnalisée sur `GetCollection`).
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && preg_match('#/support/articles/([^/]+)/historique#', $request->getPathInfo(), $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
