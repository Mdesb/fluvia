<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Support\Entity\ArticleAide;
use App\Support\Entity\TicketSupport;
use App\Support\Enum\PorteeArticle;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

        // Cloisonnement (D3/D8) — le ticket ($data) est confronté au périmètre par `read: true` +
        // PerimetreSupportExtension, mais l'article est résolu depuis le corps par un `find()` direct,
        // hors des extensions. On exige qu'il soit visible dans l'établissement du ticket : global, ou
        // local du même établissement. Sans quoi on lierait un article local d'un autre établissement
        // (fuite de contenu inter-établissements). Échec fermé en 404 (anti-oracle).
        $etablissementTicket = $data->getEtablissement();
        $memeEtablissement = $etablissementTicket !== null
            && $article->getEtablissement() !== null
            && (string) $article->getEtablissement()->getId() === (string) $etablissementTicket->getId();
        $articleVisible = $article->getPortee() === PorteeArticle::Global || $memeEtablissement;
        if (!$articleVisible) {
            throw new NotFoundHttpException('Article introuvable.');
        }

        $data->ajouterArticleLie($article);
        $data->toucherDateMaj();

        $this->em->flush();

        return $data;
    }
}
