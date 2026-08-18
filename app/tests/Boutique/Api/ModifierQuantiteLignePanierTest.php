<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Comble des manques boutique (optionnel) : `POST /boutique/paniers/{id}/lignes/{ligneId}/quantite`
 * évite un retrait + ré-ajout côté front pour changer une quantité.
 */
final class ModifierQuantiteLignePanierTest extends BoutiqueApiTestCase
{
    public function testLaQuantiteEstModifieeEtLeTotalRecalcule(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1],
        ]);
        self::assertResponseIsSuccessful();
        $ligneId = (string) $reponse->toArray()['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes/' . $ligneId . '/quantite', [
            'headers' => $entete,
            'json' => ['quantite' => 3],
        ]);
        self::assertResponseIsSuccessful();

        $panier = $client->request('GET', '/api/boutique/paniers/' . $panierId, ['headers' => $entete])->toArray();
        self::assertSame(3, $panier['lignes'][0]['quantite']);
        self::assertSame('36.00', $panier['total']);
    }

    public function testQuantiteInvalideEstRefusee(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1],
        ]);
        $ligneId = (string) $reponse->toArray()['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes/' . $ligneId . '/quantite', [
            'headers' => $entete,
            'json' => ['quantite' => 0],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testModificationRefuseeSansJeton(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => [PanierProprietaireGuard::HEADER => $jeton],
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1],
        ]);
        $ligneId = (string) $reponse->toArray()['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes/' . $ligneId . '/quantite', [
            'json' => ['quantite' => 2],
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
