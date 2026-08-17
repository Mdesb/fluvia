<?php

declare(strict_types=1);

namespace App\Tests\Stock\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\Entity\Produit;
use App\Securite\Service\ContexteEtablissement;
use App\Stock\DataFixtures\StockFixtures;
use App\Stock\Entity\ParametrageStock;
use App\Tests\Stock\StockApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Inventaire physique (US-STOCK-11, RG-STOCK-12, CA-13) : quantité théorique figée au lancement,
 * écart calculé au comptage, régularisation valorisée au coût de la dernière couche active, clôture
 * append-only (une ligne déjà comptée ne peut plus être modifiée après clôture).
 */
final class InventaireApiTest extends StockApiTestCase
{
    public function testCa13InventaireEcartRegularisationEtCloture(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $etabIri = '/api/etablissements/' . $idA;
        $entetePatch = ['auth_bearer' => $entete['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $idA, 'Content-Type' => 'application/merge-patch+json']];

        // Seuil de significativité à 5% (pourcentage) pour illustrer le flag `significatif` (CA-13).
        $parametrage = $this->entite(ParametrageStock::class, ['etablissement' => Uuid::fromString($idA)]);
        $client->request('PATCH', '/api/stock_parametrages/' . $parametrage->getId(), $entetePatch + [
            'json' => ['seuilEcartSignificatifPourcentage' => '5.00'],
        ]);
        self::assertResponseIsSuccessful();

        $article = $client->request('POST', '/api/article_stocks', $entete + [
            'json' => [
                'etablissement' => $etabIri,
                'codeEAN' => '5901234123457',
                'libelle' => 'Mug boutique',
                'unite' => 'piece',
                'prixAchatHT' => '4.0000',
                'tauxTvaAchat' => '20.00',
                'seuilMin' => '2.000',
                'seuilMax' => '80.000',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $produit = $this->entite(Produit::class, ['libelleRecherche' => StockFixtures::PRODUIT_BOUTIQUE]);
        $client->request('POST', '/api/stock/articles/' . $article['id'] . '/rattacher-produit', $entete + [
            'json' => ['produit' => (string) $produit->getId()],
        ]);
        self::assertResponseIsSuccessful();

        // Réception de 50 unités à 4,00 € → disponibilité théorique = 50.
        $this->receptionner($client, $entete, $etabIri, $article['id'], '50.000', '4.0000');

        // --- Lancement de l'inventaire (snapshot théorique figé = 50) ---
        $inventaire = $client->request('POST', '/api/stock_inventaires', $entete + [
            'json' => ['perimetre' => 'tous'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $lignes = $inventaire['lignes'] ?? [];
        self::assertNotEmpty($lignes);
        $ligneId = null;
        foreach ($lignes as $ligne) {
            if (str_ends_with((string) $ligne['articleStock'], $article['id'])) {
                $ligneId = $ligne['id'];
                self::assertSame('50.000', $ligne['quantiteTheorique']);
            }
        }
        self::assertNotNull($ligneId, 'Ligne inventaire de l\'article introuvable.');

        // --- Comptage : 46 unités relevées → écart = −4, significatif (8% > seuil 5%) ---
        $ligneComptee = $client->request('PATCH', '/api/stock/lignes-inventaire/' . $ligneId, $entetePatch + [
            'json' => ['quantiteComptee' => '46.000'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('-4.000', $ligneComptee['ecart']);
        self::assertTrue($ligneComptee['significatif']);

        // --- Régularisation (admin a aussi stock.valider_ecart) : ajustement_négatif de 4 unités ---
        $client->request('POST', '/api/stock/lignes-inventaire/' . $ligneId . '/regulariser', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $produitApres = $client->request('GET', '/api/produits/' . $produit->getId(), $entete)->toArray();
        self::assertSame(46, $produitApres['stock']['disponibilite']);

        $ligneApres = $client->request('GET', '/api/stock_ligne_inventaires/' . $ligneId, $entete)->toArray();
        self::assertNotNull($ligneApres['mouvementRegularisation']);

        // --- Clôture : append-only, un nouveau comptage sur la même ligne est refusé ---
        $client->request('POST', '/api/stock/inventaires/' . $inventaire['id'] . '/cloturer', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $client->request('PATCH', '/api/stock/lignes-inventaire/' . $ligneId, $entetePatch + [
            'json' => ['quantiteComptee' => '45.000'],
        ]);
        self::assertResponseStatusCodeSame(409, 'Inventaire clôturé : ligne non modifiable (append-only).');
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function receptionner(object $client, array $entete, string $etabIri, string $articleId, string $quantite, string $prix): void
    {
        $fournisseur = $client->request('POST', '/api/stock_fournisseurs', $entete + [
            'json' => ['etablissement' => $etabIri, 'raisonSociale' => 'Grossiste Boutique SARL'],
        ])->toArray();

        $reception = $client->request('POST', '/api/stock_reception_achats', $entete + [
            'json' => [
                'etablissement' => $etabIri,
                'fournisseur' => '/api/stock_fournisseurs/' . $fournisseur['id'],
                'date' => '2026-03-01',
                'numeroBonLivraison' => 'BL-INIT',
            ],
        ])->toArray();

        $client->request('POST', '/api/stock_ligne_reception_achats', $entete + [
            'json' => [
                'reception' => '/api/stock_reception_achats/' . $reception['id'],
                'articleStock' => '/api/article_stocks/' . $articleId,
                'quantiteRecue' => $quantite,
                'prixAchatUnitaireHT' => $prix,
            ],
        ]);

        $client->request('POST', '/api/stock/receptions-achat/' . $reception['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
    }
}
