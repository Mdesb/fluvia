<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Stock\ApiResource\ValorisationArticle;
use App\Stock\Entity\ArticleStock;
use App\Stock\Service\MoteurValorisationFifoLifo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /stock/articles/{id}/valorisation?date=` (RG-STOCK-18, §2.4 du plan, CA-14). Sans paramètre
 * `date`, valorisation courante ; sinon reconstruction historique à la date fournie (ISO 8601).
 *
 * @implements ProviderInterface<ValorisationArticle>
 */
final class ValorisationArticleProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MoteurValorisationFifoLifo $moteur,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ValorisationArticle
    {
        $id = $uriVariables['id'] ?? null;
        $article = \is_string($id) && Uuid::isValid($id) ? $this->em->getRepository(ArticleStock::class)->find($id) : null;
        if (!$article instanceof ArticleStock) {
            throw new NotFoundHttpException('Article de stock introuvable.');
        }

        $parametreDate = $this->requestStack->getCurrentRequest()?->query->get('date');
        $date = \is_string($parametreDate) && $parametreDate !== '' ? new \DateTimeImmutable($parametreDate) : new \DateTimeImmutable();

        $dto = new ValorisationArticle();
        $dto->id = (string) $article->getId();
        $dto->date = $date->format(\DateTimeInterface::ATOM);
        $dto->valorisation = \is_string($parametreDate) && $parametreDate !== ''
            ? $this->moteur->valoriserADate($article, $date)
            : $this->moteur->valoriserCourant($article);

        return $dto;
    }
}
