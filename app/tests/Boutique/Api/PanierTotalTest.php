<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Comble des manques boutique : `GET /boutique/paniers/{id}` expose le prix par ligne + le total
 * (moteur `ResolveurPrix`/`PanierCalculateur` M1/M2 réutilisé), et vérifie désormais impérativement le
 * jeton de panier comme les autres actions du tunnel.
 */
final class PanierTotalTest extends BoutiqueApiTestCase
{
    public function testLeTotalEtLePrixParLigneSontExposesAvecLeBonJeton(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 2],
        ]);
        self::assertResponseIsSuccessful();

        $panier = $client->request('GET', '/api/boutique/paniers/' . $panierId, ['headers' => $entete])->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame('24.00', $panier['total'], 'RG-M1-01 : 2 × 12.00 € (produit simple boutique).');
        self::assertCount(1, $panier['lignes']);
        self::assertSame('12.00', $panier['lignes'][0]['prixUnitaire']);
        self::assertSame('24.00', $panier['lignes'][0]['montantLigne']);
    }

    public function testGetPanierSansJetonEstRefuse(): void
    {
        [$client, $panierId] = $this->ouvrirPanierInviteA();

        $client->request('GET', '/api/boutique/paniers/' . $panierId);
        self::assertResponseStatusCodeSame(403, 'Jeton de panier requis, même en lecture — cohérent avec les autres actions du tunnel.');
    }

    public function testGetPanierAvecUnJetonEtrangerEstRefuse(): void
    {
        [, $panierId] = $this->ouvrirPanierInviteA();
        [$autreClient] = $this->ouvrirPanierInviteA();

        $autreClient->request('GET', '/api/boutique/paniers/' . $panierId, [
            'headers' => [PanierProprietaireGuard::HEADER => 'jeton-invente-au-hasard'],
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
