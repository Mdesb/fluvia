<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stock\Entity\ArticleStock;
use App\Stock\Service\RattacherArticleAuProduitHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /stock/articles/{id}/detacher-produit` — refuse si solde de lots > 0 (§8 spec).
 *
 * @implements ProcessorInterface<mixed, ArticleStock>
 */
final class DetacherProduitProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RattacherArticleAuProduitHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ArticleStock
    {
        \assert($data instanceof ArticleStock);

        $this->handler->detacher($data);
        $this->em->flush();

        return $data;
    }
}
