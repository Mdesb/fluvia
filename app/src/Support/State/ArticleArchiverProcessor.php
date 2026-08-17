<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Support\Entity\ArticleAide;
use App\Support\Enum\StatutArticle;
use App\Support\Service\ScopeArticleVerificateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POST /support/articles/{id}/archiver (§4.2 spec, cas limite §8) : retire l'article de la
 * recherche/navigation ; reste accessible par lien direct (`ARTICLE_LIRE` refuse néanmoins un
 * lecteur non rédacteur — bandeau « archivé » relève du front).
 *
 * @implements ProcessorInterface<ArticleAide, ArticleAide>
 */
final class ArticleArchiverProcessor implements ProcessorInterface
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

        $data->setStatut(StatutArticle::Archive);
        $this->em->flush();

        return $data;
    }
}
