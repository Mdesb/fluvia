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

    /**
     * Cloisonnement (D3/D8) — le `produit` du rattachement est résolu depuis le corps (find() direct),
     * hors des extensions. Rattacher un article de A à un produit commercialisé UNIQUEMENT dans un autre
     * établissement doit être refusé (404) : sinon référence stock cross-établissement.
     */
    public function testRattacherAUnProduitDunAutreEtablissementRenvoie404(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Article sur A.
        $article = $client->request('POST', '/api/article_stocks', $entete + [
            'json' => $this->corpsArticle(self::EAN_VALIDE),
        ])->toArray();
        self::assertResponseIsSuccessful();

        // Produit commercialisé UNIQUEMENT dans l'établissement B (réutilise le type d'un produit fixture).
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabB = $em->getRepository(\App\Organisation\Entity\Etablissement::class)
            ->findOneBy(['nom' => \App\DataFixtures\SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);
        $type = $this->entite(Produit::class, ['libelleRecherche' => StockFixtures::PRODUIT_BOUTIQUE])->getType();

        $produitB = (new Produit())
            ->setType($type)
            ->setLibelle(['fr' => 'Produit B only (test cloisonnement)'])
            ->setLibelleRecherche('Produit B only ' . uniqid())
            ->setCode('PRD-BONLY-' . substr(uniqid(), -6))
            ->setCanaux(['guichet'])
            ->setStatut(\App\Offre\Enum\StatutProduit::Brouillon)
            ->addEtablissement($etabB);
        $em->persist($produitB);
        $em->flush();

        $reponse = $client->request('POST', '/api/stock/articles/' . $article['id'] . '/rattacher-produit', $entete + [
            'json' => ['produit' => (string) $produitB->getId()],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
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
