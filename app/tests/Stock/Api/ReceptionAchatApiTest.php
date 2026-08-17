<?php

declare(strict_types=1);

namespace App\Tests\Stock\Api;

use App\Offre\Entity\Produit;
use App\Stock\DataFixtures\StockFixtures;
use App\Tests\Stock\StockApiTestCase;

/**
 * Commande d'achat → réception (US-STOCK-03/04, RG-STOCK-04/05) : aucun mouvement de stock tant
 * qu'aucune réception n'est validée (CA-4) ; réception partielle à prix différent du prix commandé
 * crée une couche de coût et augmente `Stock.disponibilité` M1 d'autant, la commande passe
 * `partiellement_reçue` puis `reçue` au complément (CA-5).
 */
final class ReceptionAchatApiTest extends StockApiTestCase
{
    public function testCa4Et5CycleCommandeReception(): void
    {
        [$client, $entete] = $this->adminSurA();
        $etabIri = '/api/etablissements/' . $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM);

        $fournisseur = $client->request('POST', '/api/stock_fournisseurs', $entete + [
            'json' => ['etablissement' => $etabIri, 'raisonSociale' => 'Grossiste Boutique SARL'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $article = $client->request('POST', '/api/article_stocks', $entete + [
            'json' => [
                'etablissement' => $etabIri,
                'codeEAN' => '5901234123457',
                'libelle' => 'Mug boutique',
                'unite' => 'piece',
                'prixAchatHT' => '4.0000',
                'tauxTvaAchat' => '20.00',
                'seuilMin' => '5.000',
                'seuilMax' => '50.000',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $produit = $this->entite(Produit::class, ['libelleRecherche' => StockFixtures::PRODUIT_BOUTIQUE]);
        $client->request('POST', '/api/stock/articles/' . $article['id'] . '/rattacher-produit', $entete + [
            'json' => ['produit' => (string) $produit->getId()],
        ]);
        self::assertResponseIsSuccessful();

        // --- Commande d'achat de 100 unités à 4,00 € HT ---
        $commande = $client->request('POST', '/api/stock_commande_achats', $entete + [
            'json' => ['etablissement' => $etabIri, 'fournisseur' => '/api/stock_fournisseurs/' . $fournisseur['id'], 'dateCommande' => '2026-03-01'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $ligneCommande = $client->request('POST', '/api/stock_ligne_commande_achats', $entete + [
            'json' => [
                'commandeAchat' => '/api/stock_commande_achats/' . $commande['id'],
                'articleStock' => '/api/article_stocks/' . $article['id'],
                'quantiteCommandee' => '100.000',
                'prixAchatUnitaireHT' => '4.0000',
                'tauxTVA' => '20.00',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/stock/commandes-achat/' . $commande['id'] . '/envoyer', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        // CA-4 — commande envoyée : disponibilité inchangée, aucun mouvement.
        self::assertSame(0, $this->disponibiliteProduit($client, $entete, $produit->getId()));

        // --- Réception partielle 60/100 à un prix réel différent (4,10 € vs 4,00 € commandé) ---
        $reception1 = $client->request('POST', '/api/stock_reception_achats', $entete + [
            'json' => [
                'commandeAchat' => '/api/stock_commande_achats/' . $commande['id'],
                'etablissement' => $etabIri,
                'fournisseur' => '/api/stock_fournisseurs/' . $fournisseur['id'],
                'date' => '2026-03-05',
                'numeroBonLivraison' => 'BL-001',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/stock_ligne_reception_achats', $entete + [
            'json' => [
                'reception' => '/api/stock_reception_achats/' . $reception1['id'],
                'articleStock' => '/api/article_stocks/' . $article['id'],
                'ligneCommandeAchat' => '/api/stock_ligne_commande_achats/' . $ligneCommande['id'],
                'quantiteRecue' => '60.000',
                'prixAchatUnitaireHT' => '4.1000',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/stock/receptions-achat/' . $reception1['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        self::assertSame(60, $this->disponibiliteProduit($client, $entete, $produit->getId()), 'CA-5 : disponibilité +60.');
        $commandeApres1 = $client->request('GET', '/api/stock_commande_achats/' . $commande['id'], $entete)->toArray();
        self::assertSame('partiellement_recue', $commandeApres1['statut']);

        // --- Complément 40/100 ---
        $reception2 = $client->request('POST', '/api/stock_reception_achats', $entete + [
            'json' => [
                'commandeAchat' => '/api/stock_commande_achats/' . $commande['id'],
                'etablissement' => $etabIri,
                'fournisseur' => '/api/stock_fournisseurs/' . $fournisseur['id'],
                'date' => '2026-03-10',
                'numeroBonLivraison' => 'BL-002',
            ],
        ])->toArray();

        $client->request('POST', '/api/stock_ligne_reception_achats', $entete + [
            'json' => [
                'reception' => '/api/stock_reception_achats/' . $reception2['id'],
                'articleStock' => '/api/article_stocks/' . $article['id'],
                'ligneCommandeAchat' => '/api/stock_ligne_commande_achats/' . $ligneCommande['id'],
                'quantiteRecue' => '40.000',
                'prixAchatUnitaireHT' => '4.0000',
            ],
        ]);

        $client->request('POST', '/api/stock/receptions-achat/' . $reception2['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        self::assertSame(100, $this->disponibiliteProduit($client, $entete, $produit->getId()));
        $commandeApres2 = $client->request('GET', '/api/stock_commande_achats/' . $commande['id'], $entete)->toArray();
        self::assertSame('recue', $commandeApres2['statut']);
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function disponibiliteProduit(object $client, array $entete, \Symfony\Component\Uid\Uuid $produitId): int
    {
        $reponse = $client->request('GET', '/api/produits/' . $produitId, $entete)->toArray();

        return (int) ($reponse['stock']['disponibilite'] ?? -1);
    }
}
