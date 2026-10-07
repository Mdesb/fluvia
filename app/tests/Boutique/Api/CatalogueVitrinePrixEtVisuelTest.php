<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeTarif;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Comble des manques boutique : prix public + visuel au catalogue public
 * (`GET /boutique/vitrines/{id}/catalogue`). Le moteur de prix M1 (`ResolveurPrix`) est réutilisé tel
 * quel ; aucune fuite d'un produit non publié.
 */
final class CatalogueVitrinePrixEtVisuelTest extends BoutiqueApiTestCase
{
    public function testLeCatalogueExposeLePrixEtLeVisuelDUnProduitPublie(): void
    {
        $client = static::createClient();
        $catalogue = $client->request('GET', '/api/boutique/vitrines/' . $this->idVitrineA() . '/catalogue')->toArray();
        self::assertResponseIsSuccessful();

        $simple = $this->trouverProduit($catalogue['produits'], BoutiqueFixtures::PRODUIT_SIMPLE_CODE);
        self::assertNotNull($simple, 'Le produit publié doit apparaître au catalogue.');
        self::assertSame('/assets/produits/billet-simple.jpg', $simple['visuel'], 'Le visuel du produit (champsPerso[visuelUrl]) doit être exposé.');
        self::assertSame('12.00', $simple['prix']['min']);
        self::assertSame('12.00', $simple['prix']['max']);
    }

    public function testAucunProduitNonPublieNiHorsPerimetreNeFuiteAuCatalogue(): void
    {
        // Les produits d'OffreFixtures sont publiés depuis le 06/10/2026 (la caisse les vend) : le
        // brouillon dont ce test a besoin est posé ici, explicitement, AVANT de lire le catalogue.
        $this->entite(Produit::class, ['code' => 'PRD-ENTREE01'])->setStatut(\App\Offre\Enum\StatutProduit::Brouillon);
        $this->em()->flush();

        $client = static::createClient();
        $catalogue = $client->request('GET', '/api/boutique/vitrines/' . $this->idVitrineA() . '/catalogue')->toArray();
        self::assertResponseIsSuccessful();

        // Produit OffreFixtures remis en brouillon ci-dessus : ne doit jamais apparaître.
        $produitBrouillon = $this->entite(Produit::class, ['code' => 'PRD-ENTREE01']);
        self::assertSame(\App\Offre\Enum\StatutProduit::Brouillon, $produitBrouillon->getStatut());
        self::assertNull($this->trouverProduit($catalogue['produits'], 'PRD-ENTREE01'));
    }

    public function testFourchetteAPartirDeQuandPlusieursTarifsSontCommercialisesEnLigne(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        $saison = $this->entite(Saison::class, ['nom' => OffreFixtures::SAISON]);

        // Deuxième tarif, visible partout (visibiliteCanal vide), prix supérieur — simule un tarif
        // « réduit »/« majoré » à côté du plein tarif déjà en fixtures.
        $tarifReduit = (new TypeTarif())->setNom('Tarif week-end (test)')->setVisibiliteCanal([]);
        $this->em()->persist($tarifReduit);
        $grille = (new GrilleTarifaire())->setProduit($produit)->setTypeTarif($tarifReduit)->setSaison($saison)->setPrix('20.00');
        $this->em()->persist($grille);
        $this->em()->flush();

        $client = static::createClient();
        $catalogue = $client->request('GET', '/api/boutique/vitrines/' . $this->idVitrineA() . '/catalogue')->toArray();
        $simple = $this->trouverProduit($catalogue['produits'], BoutiqueFixtures::PRODUIT_SIMPLE_CODE);

        self::assertSame('12.00', $simple['prix']['min']);
        self::assertSame('20.00', $simple['prix']['max'], '« à partir de » : le front peut afficher min < max.');
    }

    /** @param list<array<string, mixed>> $produits */
    private function trouverProduit(array $produits, string $code): ?array
    {
        foreach ($produits as $produit) {
            if ($produit['code'] === $code) {
                return $produit;
            }
        }

        return null;
    }
}
