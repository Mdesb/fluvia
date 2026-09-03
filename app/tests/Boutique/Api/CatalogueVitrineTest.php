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

        // ⚠ DEUX NOMS DISTINCTS, PAS SEULEMENT UN NOM PRESENT. Un catalogue qui rendrait la même
        // chaîne pour les deux vitrines satisferait `assertArrayHasKey` et resterait une marque
        // commune — le défaut qu'on corrige, déguisé en correction.
        $catA = $client->request('GET', '/api/boutique/vitrines/' . $idA . '/catalogue')->toArray();
        $catB = $client->request('GET', '/api/boutique/vitrines/' . $idB . '/catalogue')->toArray();
        self::assertNotSame(
            $catA['nom'] ?? null,
            $catB['nom'] ?? null,
            'CA-1 : deux boutiques ne peuvent pas s’annoncer sous le même nom.',
        );

        $catalogue = $client->request('GET', '/api/boutique/vitrines/' . $idA . '/catalogue')->toArray();
        // ⚠ LE NOM DE LA BOUTIQUE, SANS LEQUEL LA PAGE DE PAIEMENT NE DIT PAS A QUI ON PAIE.
        //
        // Le catalogue ne portait que des identifiants (`etablissement` est un UUID nu), donc la
        // facade publique affichait « Billetterie » en dur sur TOUTES les vitrines — exactement la
        // « marque editeur commune » que l'assertion sur les logos, quelques lignes plus haut,
        // interdit. Les deux moities de CA-1 sont maintenant clouees.
        self::assertArrayHasKey('nom', $catalogue, 'CA-1 : sans nom, la boutique ne peut pas se nommer.');
        self::assertNotSame('', (string) $catalogue['nom']);
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
