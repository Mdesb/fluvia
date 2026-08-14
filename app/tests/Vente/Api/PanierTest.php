<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Vente\DataFixtures\VenteFixtures;
use App\Tests\Vente\VenteApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Composition du panier : recalcul temps réel & ligne modifiable/supprimable (CA-3), prix grille +
 * promo auto (CA-4), bénéficiaire requis (CA-5), blocage stock (CA-6), rattachement client (CA-7).
 */
final class PanierTest extends VenteApiTestCase
{
    /** CA-3 — Panier : ajout, total temps réel, modification et suppression de ligne, vider. */
    public function testCa3PanierModifiableEtSupprimable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $apresAjout = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => $this->ligneEntree(2),
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $apresAjout['lignes']);
        $ligneId = $apresAjout['lignes'][0]['id'];
        // 2 × 5,50 = 11,00 − 10% promo = 9,90.
        self::assertSame('9.90', $apresAjout['total']);

        // Modifier la quantité à 1 → recalcul instantané (4,95).
        $apresModif = $client->request('POST', '/api/ventes/' . $vente['id'] . '/modifier-ligne', $entete + [
            'json' => ['ligne' => $ligneId, 'quantite' => 1],
        ])->toArray();
        self::assertSame('4.95', $apresModif['total']);

        // Retirer la ligne → panier vide.
        $apresRetrait = $client->request('POST', '/api/ventes/' . $vente['id'] . '/retirer-ligne', $entete + [
            'json' => ['ligne' => $ligneId],
        ])->toArray();
        self::assertCount(0, $apresRetrait['lignes']);
        self::assertSame('0.00', $apresRetrait['total']);
    }

    /** CA-4 — Prix issu de la grille M1 (tarif × saison), promotions auto visibles, recalcul immédiat. */
    public function testCa4PrixGrilleEtPromotionAuto(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => $this->ligneEntree(1),
        ])->toArray();
        self::assertResponseIsSuccessful();
        $ligne = $reponse['lignes'][0];
        self::assertSame('5.50', $ligne['prixUnitaire'], 'Prix issu de la grille M1 (tarif plein).');
        self::assertNotEmpty($ligne['promotionsAppliquees'], 'La promo guichet éligible s\'applique automatiquement.');
        self::assertSame('4.95', $ligne['montantLigne'], 'Total ligne remises comprises (−10%).');
    }

    /** CA-5 / RG-M2-04 — Bénéficiaire requis : ajout refusé sans bénéficiaire, accepté avec. */
    public function testCa5BeneficiaireRequis(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $ligneGold = [
            'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_GOLD),
            'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
            'quantite' => 1,
        ];

        // Sans bénéficiaire : refusé (abonnement nominatif).
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + ['json' => $ligneGold]);
        self::assertResponseStatusCodeSame(422);

        // Avec bénéficiaire : accepté.
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => $ligneGold + ['beneficiaire' => (string) Uuid::v4()],
        ]);
        self::assertResponseIsSuccessful();
    }

    /** CA-6 / RG-M2-04 — Produit en stock à 0 : non ajoutable (« stock épuisé ») ; non géré : jamais bloqué. */
    public function testCa6BlocageStock(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        // Produit géré en stock à 0 : refusé.
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(VenteFixtures::PRODUIT_STOCK_ZERO),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('stock', $client->getResponse()->getContent(false));

        // Produit non géré en stock (entrée) : jamais bloqué.
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + ['json' => $this->ligneEntree(1)]);
        self::assertResponseIsSuccessful();
    }

    /** CA-7 — Rattachement client (recherche/création) ; vente comptoir anonyme possible. */
    public function testCa7RattachementClientOuAnonyme(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        // Vente anonyme : aucun client rattaché.
        $anonyme = $this->creerVente($client, $entete, $session['id']);
        self::assertNull($anonyme['client']);

        // Rattachement par recherche (M4 stub).
        $vente = $this->creerVente($client, $entete, $session['id']);
        $rattachee = $client->request('POST', '/api/ventes/' . $vente['id'] . '/client', $entete + [
            'json' => ['recherche' => 'dupont@example.com'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotNull($rattachee['client']);
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneEntree(int $quantite): array
    {
        return [
            'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
            'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
            'quantite' => $quantite,
        ];
    }
}
