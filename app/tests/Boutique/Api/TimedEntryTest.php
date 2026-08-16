<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Produit timed-entry — choix de créneau obligatoire avant ajout au panier (US-L8-02, RG-M3-02, CA-2).
 */
final class TimedEntryTest extends BoutiqueApiTestCase
{
    public function testCa2AjoutSansCreneauRefuseAvecCreneauAccepteEtResteDecroit(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        // Sans créneau : ajout refusé (RG-M3-02).
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId()],
        ]);
        self::assertResponseStatusCodeSame(422);

        $creneaux = $client->request('GET', '/api/boutique/produits/' . $produit->getId() . '/creneaux')->toArray();
        self::assertNotEmpty($creneaux['creneaux']);
        $creneauId = $creneaux['creneaux'][0]['creneau'];
        $resteInitial = $creneaux['creneaux'][0]['reste'];

        // Avec créneau disponible : ajout accepté.
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'creneau' => $creneauId],
        ]);
        self::assertResponseIsSuccessful();

        // Le « Reste : n » décroît visiblement pour un autre visiteur (deuxième panier).
        [$autreClient] = $this->ouvrirPanierInviteA();
        $creneauxApres = $autreClient->request('GET', '/api/boutique/produits/' . $produit->getId() . '/creneaux')->toArray();
        self::assertSame($resteInitial - 1, $creneauxApres['creneaux'][0]['reste']);
    }
}
