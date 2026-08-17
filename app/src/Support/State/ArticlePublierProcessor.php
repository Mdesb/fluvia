<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Support\Entity\ArticleAide;
use App\Support\Entity\VersionArticle;
use App\Support\Enum\StatutArticle;
use App\Support\Service\ScopeArticleVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /support/articles/{id}/publier (RG-SUP-02, CA-2) : fige la dernière `VersionArticle` comme
 * `versionPubliee`, passe `statut = publie` — action explicite et distincte de l'enregistrement.
 *
 * @implements ProcessorInterface<ArticleAide, ArticleAide>
 */
final class ArticlePublierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScopeArticleVerificateur $verificateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ArticleAide
    {
        \assert($data instanceof ArticleAide);
        $this->verificateur->verifierOuRefuser($data);

        $derniere = $this->em->getRepository(VersionArticle::class)->createQueryBuilder('v')
            ->andWhere('v.article = :article')
            ->setParameter('article', $data->getId(), 'uuid')
            ->orderBy('v.numero', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$derniere instanceof VersionArticle) {
            throw new UnprocessableEntityHttpException('Aucune version enregistrée : impossible de publier.');
        }

        $data->setVersionPubliee($derniere);
        $data->setStatut(StatutArticle::Publie);

        $this->em->flush();

        return $data;
    }
}
