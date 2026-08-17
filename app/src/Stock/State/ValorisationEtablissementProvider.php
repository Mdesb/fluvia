<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Securite\Service\ContexteEtablissement;
use App\Stock\ApiResource\ValorisationEtablissement;
use App\Stock\Entity\ArticleStock;
use App\Stock\Service\MoteurValorisationFifoLifo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `GET /stock/valorisation?etablissement=` (RG-STOCK-18) : somme par article de l'établissement actif
 * (ou passé en paramètre) — Comptable, `stock.lire_valorisation`.
 *
 * @implements ProviderInterface<list<ValorisationEtablissement>>
 */
final class ValorisationEtablissementProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MoteurValorisationFifoLifo $moteur,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            return [];
        }

        $articles = $this->em->getRepository(ArticleStock::class)->findBy(['etablissement' => $etablissement->getId()]);

        $resultats = [];
        foreach ($articles as $article) {
            \assert($article instanceof ArticleStock);
            $dto = new ValorisationEtablissement();
            $dto->articleStock = (string) $article->getId();
            $dto->libelle = $article->getLibelle();
            $dto->valorisation = $this->moteur->valoriserCourant($article);
            $resultats[] = $dto;
        }

        return $resultats;
    }
}
