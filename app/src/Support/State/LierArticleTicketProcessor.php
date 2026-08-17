<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Support\Entity\ArticleAide;
use App\Support\Entity\TicketSupport;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /support/tickets/{id}/lier-article (CA-12, RG-SUP-14) : lie un `ArticleAide` existant au
 * ticket (visible du demandeur, complète — ne remplace pas — un message de réponse). Corps :
 * { "articleId": IRI }.
 *
 * @implements ProcessorInterface<TicketSupport, TicketSupport>
 */
final class LierArticleTicketProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TicketSupport
    {
        \assert($data instanceof TicketSupport);

        $corps = $this->lecteur->corps();
        $articleIri = $corps['articleId'] ?? null;
        if (!\is_string($articleIri) || $articleIri === '') {
            throw new UnprocessableEntityHttpException('Champ "articleId" (IRI article) requis.');
        }

        $article = $this->em->getRepository(ArticleAide::class)->find(basename($articleIri));
        if (!$article instanceof ArticleAide) {
            throw new UnprocessableEntityHttpException('Article introuvable.');
        }

        $data->ajouterArticleLie($article);
        $data->toucherDateMaj();

        $this->em->flush();

        return $data;
    }
}
