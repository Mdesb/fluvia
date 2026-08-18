<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Non-régression : `POST /boutique/paniers/{id}/lignes/{ligneId}/retirer` (§4.3 spec) — l'opération
 * portait **deux** variables d'URI (`{id}`/`{ligneId}`) avec `read: true` : le provider Doctrine par
 * défaut ne résolvait alors pas fiablement `PanierEnLigne` (`AssertionError` en pratique, endpoint
 * jamais couvert par un test jusqu'ici). Corrigé en même temps que l'ajout de l'action
 * `/quantite` (comble des manques boutique) : `read: false` + résolution manuelle, comme les autres
 * actions du tunnel.
 */
final class RetirerLignePanierTest extends BoutiqueApiTestCase
{
    public function testRetirerUneLigneFonctionneEtLibereLaPlace(): void
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

        $retrait = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes/' . $ligneId . '/retirer', ['headers' => $entete]);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $retrait->toArray()['lignes'], 'La ligne retirée disparaît immédiatement du panier.');

        $panier = $client->request('GET', '/api/boutique/paniers/' . $panierId, ['headers' => $entete])->toArray();
        self::assertCount(0, $panier['lignes']);
        self::assertSame('0.00', $panier['total']);
    }

    public function testRetirerRefuseSansJeton(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => [PanierProprietaireGuard::HEADER => $jeton],
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1],
        ]);
        $ligneId = (string) $reponse->toArray()['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes/' . $ligneId . '/retirer');
        self::assertResponseStatusCodeSame(403);
    }
}
