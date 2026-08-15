<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Api;

use App\Tests\Piscine\PiscineApiTestCase;

/**
 * Catalogue « piscine type » (US-L6-01, CA-1) : instanciation en un clic des produits entrée
 * adulte/enfant, carte 10 (bonus paramétrable), abonnement Gold, abonnement Classique et cours, via
 * le module Offre (M1) — aucune nouvelle table L6 pour le modèle lui-même.
 */
final class CatalogueTest extends PiscineApiTestCase
{
    public function testCa1InstanciationCreeLeCatalogueEnUnClic(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/piscine/modeles/piscine-type/instancier', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        $vue = $client->getResponse()->toArray();
        // Entrée adulte/enfant, carte 10, Gold, Classique, 4 cours = 9 produits.
        self::assertCount(9, $vue['produitsCrees']);

        $client->request('GET', '/api/produits', $entete + ['query' => ['itemsPerPage' => 200]]);
        $liste = $client->getResponse()->toArray();
        $codes = array_column($liste['member'] ?? $liste['hydra:member'], 'code');
        foreach (['PISC-ENTREE-ADULTE', 'PISC-ENTREE-ENFANT', 'PISC-CARTE-10', 'PISC-ABO-GOLD', 'PISC-ABO-CLASSIQUE', 'PISC-COURS-AQUAGYM'] as $code) {
            self::assertContains($code, $codes);
        }
    }

    public function testCa1ProduitGenereResteEditableSansCasserLeModele(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/piscine/modeles/piscine-type/instancier', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/produits', $entete + ['query' => ['itemsPerPage' => 200, 'code' => 'PISC-ENTREE-ADULTE']]);
        $produit = ($client->getResponse()->toArray()['member'] ?? $client->getResponse()->toArray()['hydra:member'])[0];

        $client->request('PATCH', '/api/produits/' . $produit['id'], $this->entetePatch($entete) + [
            'json' => ['libelle' => ['fr' => 'Entrée adulte (tarif révisé)']],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('Entrée adulte (tarif révisé)', $client->getResponse()->toArray()['libelle']['fr']);

        // Ré-instancier n'écrase pas le produit édité (idempotence, pas de lien vivant vers un modèle).
        $client->request('POST', '/api/piscine/modeles/piscine-type/instancier', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getResponse()->toArray()['produitsCrees'], 'Deuxième instanciation : aucun doublon créé (codes déjà existants).');

        $client->request('GET', '/api/produits/' . $produit['id'], $entete);
        self::assertSame('Entrée adulte (tarif révisé)', $client->getResponse()->toArray()['libelle']['fr'], 'Le produit édité n\'est pas écrasé par une nouvelle instanciation.');
    }

    public function testCa1CataloguePubliableEtAchetableSansEtapeSupplementaire(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/piscine/modeles/piscine-type/instancier', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/produits', $entete + ['query' => ['itemsPerPage' => 200, 'code' => 'PISC-ENTREE-ADULTE']]);
        $produit = ($client->getResponse()->toArray()['member'] ?? $client->getResponse()->toArray()['hydra:member'])[0];

        // Publication directe via l'endpoint générique M1, sans aucune étape propre à L6.
        $client->request('POST', '/api/produits/' . $produit['id'] . '/publier', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('publie', $client->getResponse()->toArray()['statut']);
    }
}
