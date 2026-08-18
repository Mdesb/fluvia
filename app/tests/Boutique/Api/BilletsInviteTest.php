<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;

/**
 * Comble des manques boutique : `GET /boutique/paniers/{id}/billets` — un acheteur **invité** (sans
 * compte) récupère ses billets à QR après paiement via le seul jeton de panier (US-L8-10/11,
 * RG-M3-04). Ne casse pas l'accès existant `/comptes/me/billets` pour un titulaire de compte
 * (non-régression).
 */
final class BilletsInviteTest extends BoutiqueApiTestCase
{
    public function testUnInviteRecupereSesBilletsQrParLeJetonDePanierApresPaiement(): void
    {
        [$client, $panierId, $jeton] = $this->finaliserAchatInvite('invite.billets@example.test');
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $reponse = $client->request('GET', '/api/boutique/paniers/' . $panierId . '/billets', ['headers' => $entete]);
        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();

        self::assertNotEmpty($donnees['billets']);
        self::assertNotEmpty($donnees['billets'][0]['qrDynamique']);
        self::assertNotEmpty($donnees['billets'][0]['identifiantSupport']);
    }

    public function testBilletsRefusesSansJetonOuAvecJetonEtranger(): void
    {
        [$client, $panierId] = $this->finaliserAchatInvite('invite.billets.sans.jeton@example.test');

        $client->request('GET', '/api/boutique/paniers/' . $panierId . '/billets');
        self::assertResponseStatusCodeSame(403, 'Aucune énumération possible sans jeton.');

        $client->request('GET', '/api/boutique/paniers/' . $panierId . '/billets', [
            'headers' => [PanierProprietaireGuard::HEADER => 'jeton-invente-au-hasard'],
        ]);
        self::assertResponseStatusCodeSame(403, 'Jeton étranger/invalide refusé.');
    }

    public function testBilletsIntrouvablesTantQueLaCommandeNEstPasPayee(): void
    {
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $client->request('GET', '/api/boutique/paniers/' . $panierId . '/billets', ['headers' => $entete]);
        self::assertResponseStatusCodeSame(404, 'Aucune commande payée rattachée à ce panier -> 404 (pas de fuite d\'état interne).');
    }

    public function testNonRegressionAccesCompteMeBilletsPourUnTitulaireConnecte(): void
    {
        // Même patron que `RemboursementTest::acheterEnTantQueTitulaireDeCompte()` (code réel) : le
        // tunnel panier reste piloté par le jeton de panier (X-Panier-Token) même quand l'identification
        // choisit le mode « compte » — le JWT (`auth_bearer`) ne sert qu'à l'espace client
        // `/boutique/comptes/me/*`, distinct du guard de propriété du panier.
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $reponseLigne = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1],
        ]);
        self::assertResponseIsSuccessful();
        $ligneId = (string) $reponseLigne->toArray()['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'compte', 'email' => BoutiqueFixtures::CLIENT_EMAIL, 'motDePasse' => BoutiqueFixtures::CLIENT_MDP],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['rgpd' => true]]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Martin', 'prenom' => 'Camille', 'dateNaissance' => '1992-03-14']]]],
        ]);
        self::assertResponseIsSuccessful();

        $reponsePayer = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseIsSuccessful();
        $donneesPaiement = $reponsePayer->toArray();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $donneesPaiement['referenceTransaction'], 'statut' => 'accepte', 'montantCentimes' => $donneesPaiement['montantCentimes']],
        ]);
        self::assertResponseIsSuccessful();

        $token = $this->jeton($client, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);
        $reponseBillets = $client->request('GET', '/api/boutique/comptes/me/billets', ['auth_bearer' => $token]);
        self::assertResponseIsSuccessful();
        $donneesBillets = $reponseBillets->toArray();
        self::assertNotEmpty($donneesBillets['billets'], 'Non-régression : accès titulaire de compte inchangé.');
    }

    /** @return array{0: Client, 1: string, 2: string} */
    private function finaliserAchatInvite(string $email): array
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

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'invite', 'email' => $email],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['rgpd' => true]]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Dupont', 'prenom' => 'Jean', 'dateNaissance' => '1990-01-01']]]],
        ]);
        self::assertResponseIsSuccessful();

        $reponsePayer = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseIsSuccessful();
        $donneesPaiement = $reponsePayer->toArray();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $donneesPaiement['referenceTransaction'], 'statut' => 'accepte', 'montantCentimes' => $donneesPaiement['montantCentimes']],
        ]);
        self::assertResponseIsSuccessful();

        return [$client, $panierId, $jeton];
    }
}
