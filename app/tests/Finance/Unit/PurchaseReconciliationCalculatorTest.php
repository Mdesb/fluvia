<?php

declare(strict_types=1);

namespace App\Tests\Finance\Unit;

use App\Compta\Entity\ProfilExploitant;
use App\Finance\SupplierInvoice\Entity\ReconciliationSettings;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Entity\SupplierInvoiceLine;
use App\Finance\SupplierInvoice\Service\PurchaseReconciliationCalculator;
use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\LigneReceptionAchat;
use App\Stock\Entity\ReceptionAchat;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * CA-3 (US-SINV-03, RG-SINV-03) — écart signalé, jamais bloquant ; §4.3 spec, sans réception aucun
 * écart calculé. Test unitaire pur (objets en mémoire, `EntityManagerInterface` mocké) : le calculateur
 * ne dépend que des relations déjà chargées, jamais d'une requête complexe.
 */
final class PurchaseReconciliationCalculatorTest extends TestCase
{
    public function testEcartPrixSignaleNonBloquant(): void
    {
        // Réception 100 x 4,00 €, facture 100 x 4,50 € (CA-3, exemple littéral de la spec).
        $article = new ArticleStock();

        $ligneReception = (new LigneReceptionAchat())
            ->setArticleStock($article)
            ->setQuantiteRecue('100.000')
            ->setPrixAchatUnitaireHT('4.0000');
        $reception = new ReceptionAchat();
        $reception->addLigne($ligneReception);

        $profil = new ProfilExploitant();
        $facture = new SupplierInvoice();
        $facture->setBusinessProfile($profil);
        $facture->setGoodsReceipt($reception);

        $ligneFacture = (new SupplierInvoiceLine())
            ->setStockArticle($article)
            ->setQuantity('100.000')
            ->setUnitPriceExclTax('4.50');
        $facture->addLine($ligneFacture);

        $calculateur = new PurchaseReconciliationCalculator($this->emSansReglages());
        $ecarts = $calculateur->calculer($facture);

        self::assertCount(1, $ecarts);
        $ecart = $ecarts[0];
        self::assertSame('0.000', $ecart->quantityGap);
        self::assertSame('0.50', $ecart->unitPriceGap);
        self::assertSame('12.50', $ecart->unitPriceGapPercent);
        // Seuil par défaut 5.00 % (`ReconciliationSettings::DEFAULT_THRESHOLD_PERCENT`), dépassé — mais
        // rien dans `PurchaseReconciliationCalculator` ne lève d'exception : signalé, jamais bloquant.
        self::assertTrue($ecart->thresholdExceeded);
    }

    public function testSansCommandeNiReceptionAucunEcartCalcule(): void
    {
        $profil = new ProfilExploitant();
        $facture = new SupplierInvoice();
        $facture->setBusinessProfile($profil);
        // Ni `purchaseOrder` ni `goodsReceipt` : achat ponctuel / prestation de service (§7 cas limite).

        $ligne = (new SupplierInvoiceLine())->setQuantity('1.000')->setUnitPriceExclTax('10.00');
        $facture->addLine($ligne);

        $calculateur = new PurchaseReconciliationCalculator($this->emSansReglages());

        self::assertSame([], $calculateur->calculer($facture));
    }

    public function testEcartSousLeSeuilNePasSignale(): void
    {
        $article = new ArticleStock();
        $ligneReception = (new LigneReceptionAchat())->setArticleStock($article)->setQuantiteRecue('10.000')->setPrixAchatUnitaireHT('10.0000');
        $reception = new ReceptionAchat();
        $reception->addLigne($ligneReception);

        $profil = new ProfilExploitant();
        $facture = new SupplierInvoice();
        $facture->setBusinessProfile($profil);
        $facture->setGoodsReceipt($reception);

        // Écart de prix de 1 % (10,10 € vs 10,00 €), sous le seuil par défaut de 5 %.
        $ligneFacture = (new SupplierInvoiceLine())->setStockArticle($article)->setQuantity('10.000')->setUnitPriceExclTax('10.10');
        $facture->addLine($ligneFacture);

        $calculateur = new PurchaseReconciliationCalculator($this->emSansReglages());
        $ecarts = $calculateur->calculer($facture);

        self::assertCount(1, $ecarts);
        self::assertFalse($ecarts[0]->thresholdExceeded);
    }

    private function emSansReglages(): EntityManagerInterface
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return $em;
    }
}
