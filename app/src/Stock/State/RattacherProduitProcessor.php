<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Stock\Entity\ArticleStock;
use App\Stock\Service\RattacherArticleAuProduitHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /stock/articles/{id}/rattacher-produit` (§0 décision n°1, CA-1). Corps : { "produit": uuid|IRI }.
 *
 * @implements ProcessorInterface<mixed, ArticleStock>
 */
final class RattacherProduitProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly RattacherArticleAuProduitHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ArticleStock
    {
        \assert($data instanceof ArticleStock);
        $article = $data;

        $corps = $this->lecteur->corps();
        $reference = $corps['produit'] ?? null;
        $segment = \is_string($reference) ? (str_contains($reference, '/') ? basename($reference) : $reference) : null;
        if ($segment === null || !Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException('Champ « produit » obligatoire (UUID ou IRI).');
        }
        $produit = $this->em->getRepository(Produit::class)->find($segment);
        if (!$produit instanceof Produit) {
            throw new UnprocessableEntityHttpException('Produit introuvable.');
        }

        // Cloisonnement (D3/D8) — l'article ($data) est confronté au périmètre par `read: true` +
        // PerimetreStockExtension, mais le `produit` est résolu depuis le corps par un `find()` direct,
        // hors des extensions. On exige que le produit soit **commercialisé dans l'établissement de
        // l'article** : sans quoi on rattachait un article de A à un produit d'un autre établissement
        // (référence cross-tenant). Échec fermé en 404 (anti-oracle).
        $etablissementArticle = $article->getEtablissement();
        $produitDansLEtablissement = $etablissementArticle !== null && $produit->getEtablissements()->exists(
            static fn (int $cle, Etablissement $etablissement): bool => (string) $etablissement->getId() === (string) $etablissementArticle->getId(),
        );
        if (!$produitDansLEtablissement) {
            throw new NotFoundHttpException('Produit introuvable.');
        }

        $this->handler->rattacher($article, $produit);
        $this->em->flush();

        return $article;
    }
}
