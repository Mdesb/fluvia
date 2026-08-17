<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Support\Entity\PieceJointeAide;
use App\Support\Service\ScopeArticleVerificateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POST pièce jointe d'article : vérifie que l'utilisateur courant a bien le scope d'écriture sur
 * l'`ArticleAide` parent (global/local établissement), même garde que `ArticleAideCreerProcessor`.
 *
 * @implements ProcessorInterface<PieceJointeAide, PieceJointeAide>
 */
final class PieceJointeAideProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScopeArticleVerificateur $verificateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PieceJointeAide
    {
        \assert($data instanceof PieceJointeAide);

        $article = $data->getArticle();
        if ($article !== null) {
            $this->verificateur->verifierOuRefuser($article);
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
