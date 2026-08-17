<?php

declare(strict_types=1);

namespace App\Tests\Stock\Api;

use App\Offre\Entity\Produit;
use App\Stock\DataFixtures\StockFixtures;
use App\Tests\Stock\StockApiTestCase;

/**
 * Article de stock : validation EAN-13/EAN-8 (CA-1), rattachement à un Produit M1 → pilotage du
 * `Stock.disponibilité` (CA-1/RG-STOCK-01), refus si le produit ne porte pas la facette `stock`.
 */
final class ArticleStockApiTest extends StockApiTestCase
{
    private const EAN_VALIDE = '5901234123457';
    private const EAN_INVALIDE = '5901234123450';
    private const EAN_8_VALIDE = '40170725';

    public function testCa1CodeEanInvalideRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/article_stocks', $entete + [
            'json' => $this->corpsArticle(self::EAN_INVALIDE),
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCa1CodeEan8ValideAccepte(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/article_stocks', $entete + [
            'json' => $this->corpsArticle(self::EAN_8_VALIDE),
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testCa1CodeEanDejaUtiliseSurLeMemeEtablissementRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/article_stocks', $entete + ['json' => $this->corpsArticle(self::EAN_VALIDE)]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/article_stocks', $entete + [
            'json' => $this->corpsArticle(self::EAN_VALIDE, 'Autre libellé'),
        ]);
        self::assertResponseStatusCodeSame(422, 'EAN déjà utilisé sur le même établissement (RG-STOCK-02).');
    }

    public function testCa1RattachementProduitPiloteLaDisponibilite(): void
    {
        [$client, $entete] = $this->adminSurA();

        $article = $client->request('POST', '/api/article_stocks', $entete + [
            'json' => $this->corpsArticle(self::EAN_VALIDE),
        ])->toArray();
        self::assertResponseIsSuccessful();

        $produit = $this->entite(Produit::class, ['libelleRecherche' => StockFixtures::PRODUIT_BOUTIQUE]);

        $rattache = $client->request('POST', '/api/stock/articles/' . $article['id'] . '/rattacher-produit', $entete + [
            'json' => ['produit' => (string) $produit->getId()],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame((string) $produit->getId(), $this->idDepuisIri($rattache['produit'] ?? ''));
    }

    /** CA-2 (RG-STOCK-01) — un ArticleStock non rattaché n'a pas de produit associé. */
    public function testCa2ArticleSansProduitResteSansRattachement(): void
    {
        [$client, $entete] = $this->adminSurA();

        $article = $client->request('POST', '/api/article_stocks', $entete + [
            'json' => $this->corpsArticle(self::EAN_VALIDE),
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertNull($article['produit'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function corpsArticle(string $ean, string $libelle = 'Mug boutique'): array
    {
        return [
            'etablissement' => '/api/etablissements/' . $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM),
            'codeEAN' => $ean,
            'libelle' => $libelle,
            'unite' => 'piece',
            'prixAchatHT' => '4.0000',
            'tauxTvaAchat' => '20.00',
            'seuilMin' => '5.000',
            'seuilMax' => '50.000',
        ];
    }

    private function idDepuisIri(mixed $iri): string
    {
        if (!\is_string($iri)) {
            return '';
        }

        return basename($iri);
    }
}
