<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Vitrine white-label & catalogue public (US-L8-01, RG-M3-01/08, CA-1).
 */
final class CatalogueVitrineTest extends BoutiqueApiTestCase
{
    public function testCa1DeuxVitrinesReflètentLeurPropreIdentiteEtCatalogue(): void
    {
        $client = static::createClient();
        $idA = $this->idVitrineA();
        $idB = $this->idVitrineB();

        $reponseA = $client->request('GET', '/api/boutique/vitrines/' . $idA);
        self::assertResponseIsSuccessful();
        $vitrineA = $reponseA->toArray();
        self::assertSame(['fr', 'en'], $vitrineA['langues']);
        self::assertSame('#0B6E4F', $vitrineA['couleurs']['primaire']);

        $reponseB = $client->request('GET', '/api/boutique/vitrines/' . $idB);
        self::assertResponseIsSuccessful();
        $vitrineB = $reponseB->toArray();
        self::assertSame(['fr'], $vitrineB['langues']);
        self::assertNotSame($vitrineA['logo'], $vitrineB['logo'], 'CA-1 : chaque vitrine porte son propre logo, sans marque éditeur commune.');

        $catalogue = $client->request('GET', '/api/boutique/vitrines/' . $idA . '/catalogue')->toArray();
        self::assertNotEmpty($catalogue['produits'], 'CA-1 : le catalogue reflète les produits publiés/canal en_ligne de M1.');
        foreach ($catalogue['produits'] as $produit) {
            self::assertArrayHasKey('disponibilite', $produit, 'RG-M3-08 : disponibilité affichée en temps réel.');
        }
    }

    public function testCatalogueEstAccessiblePublicAccessSansAuthentification(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/boutique/vitrines/' . $this->idVitrineA() . '/catalogue');
        self::assertResponseIsSuccessful();
    }
}
