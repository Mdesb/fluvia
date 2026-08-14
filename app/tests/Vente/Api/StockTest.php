<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Vente\DataFixtures\VenteFixtures;
use App\Tests\Vente\VenteApiTestCase;

/**
 * Décrément de stock atomique à la validation (§6 du plan, RG-M1-10) : deux ventes composées sur un
 * produit à stock 1 ; la première validation réussit et épuise le stock, la seconde échoue (422),
 * évitant la survente.
 */
final class StockTest extends VenteApiTestCase
{
    public function testDecrementAtomiqueEviteSurvente(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        // Deux paniers composés AVANT toute validation (stock encore à 1 lors des deux ajouts).
        $venteA = $this->composerPlaceLimitee($client, $entete, $session['id']);
        $venteB = $this->composerPlaceLimitee($client, $entete, $session['id']);

        // Validation A : décrément 1 → 0, succès.
        $client->request('POST', '/api/ventes/' . $venteA . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        // Validation B : stock déjà à 0 → décrément conditionnel refusé (422, survente évitée).
        $client->request('POST', '/api/ventes/' . $venteB . '/valider', $entete + ['json' => []]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function composerPlaceLimitee(object $client, array $entete, string $sessionId): string
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(VenteFixtures::PRODUIT_STOCK_UN),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '3.00']]);

        return $vente['id'];
    }
}
