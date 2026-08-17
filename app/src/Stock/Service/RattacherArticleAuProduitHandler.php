<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\Stock;
use App\Offre\Entity\TypeProduit;
use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\LotStock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Rattachement `ArticleStock` ↔ `Produit` M1 (§0 décision n°1 du plan, CA-1) : (a) vérifie la facette
 * `stock` (RG-M1-02) ; (b) crée un `Stock` dédié si absent (même geste que la création d'une
 * `Formule`/`CarteMultiEntrees` satellite en cascade) ; (c) refuse un rattachement à un `Stock` partagé
 * (422, cas non couvert §9 Risque n°2 du plan).
 */
final class RattacherArticleAuProduitHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function rattacher(ArticleStock $article, Produit $produit): void
    {
        $type = $produit->getType();
        if (!$type instanceof TypeProduit || !$type->aFacette(TypeProduit::FACETTE_STOCK)) {
            throw new UnprocessableEntityHttpException('Le produit doit porter la facette « stock » pour être rattaché (RG-M1-02).');
        }

        $dejaRattache = $this->em->getRepository(ArticleStock::class)->findOneBy(['produit' => $produit->getId()]);
        if ($dejaRattache instanceof ArticleStock && (string) $dejaRattache->getId() !== (string) $article->getId()) {
            throw new ConflictHttpException('Ce produit est déjà rattaché à un autre article de stock (RG-STOCK-01, 0..1).');
        }

        $stock = $produit->getStock();
        if ($stock === null) {
            $stock = new Stock();
            $stock->setType(Stock::TYPE_DEDIE)->setDisponibilite(0);
            $this->em->persist($stock);
            $produit->setStock($stock);
        } elseif ($stock->getType() === Stock::TYPE_PARTAGE) {
            throw new UnprocessableEntityHttpException('Rattachement à un Stock partagé non supporté dans cette version (§0 décision n°1 du plan).');
        }

        $article->setProduit($produit)->toucherModifieLe();
    }

    /** Détachement — refusé si un solde de lots subsiste (§8 spec, article non mouvementé à zéro). */
    public function detacher(ArticleStock $article): void
    {
        $solde = (string) $this->em->getRepository(LotStock::class)->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.quantiteRestante), 0)')
            ->andWhere('l.articleStock = :article')
            ->setParameter('article', $article->getId(), 'uuid')
            ->getQuery()->getSingleScalarResult();

        if (ArithmetiqueDecimale::versEntier($solde, 3) > 0) {
            throw new UnprocessableEntityHttpException('Détachement refusé : un solde de lots subsiste sur cet article.');
        }

        $article->setProduit(null)->toucherModifieLe();
    }
}
