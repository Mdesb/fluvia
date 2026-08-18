<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Unit;

use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Service\PanierTarificationHandler;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeTarif;
use App\Offre\Service\ResolveurPrix;
use App\Vente\Service\PanierCalculateur;
use PHPUnit\Framework\TestCase;

/**
 * Test unitaire (sans base de données) de `PanierTarificationHandler` — isole la logique de calcul du
 * moteur de sérialisation/HTTP pour un diagnostic rapide.
 */
final class PanierTarificationHandlerTest extends TestCase
{
    public function testCalculeLePrixUnitaireEtLeTotalPourUneLigneSimple(): void
    {
        $saison = (new Saison())->setNom('Saison test')->setPriorite(0)
            ->setDateDebut(new \DateTimeImmutable('2020-01-01'))->setDateFin(new \DateTimeImmutable('2030-12-31'));
        $typeTarif = (new TypeTarif())->setNom('Plein tarif')->setVisibiliteCanal([]);

        $produit = new Produit();
        $grille = (new GrilleTarifaire())->setProduit($produit)->setTypeTarif($typeTarif)->setSaison($saison)->setPrix('12.00');
        $produit->addGrille($grille);

        $ligne = (new LignePanierEnLigne())->setProduit($produit)->setQuantite(2);
        $panier = new PanierEnLigne();
        $panier->addLigne($ligne);

        $handler = new PanierTarificationHandler(new ResolveurPrix(), new PanierCalculateur());
        $handler->calculer($panier);

        self::assertSame('12.00', $ligne->getPrixUnitaire());
        self::assertSame('24.00', $ligne->getMontantLigne());
        self::assertSame('24.00', $panier->getTotal());
    }

    public function testTotalZeroPourUnPanierSansLigne(): void
    {
        $panier = new PanierEnLigne();
        $handler = new PanierTarificationHandler(new ResolveurPrix(), new PanierCalculateur());
        $handler->calculer($panier);

        self::assertSame('0.00', $panier->getTotal());
    }
}
