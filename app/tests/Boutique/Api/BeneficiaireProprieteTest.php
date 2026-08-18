<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Revue de sécurité — faille majeure (#3) : un `beneficiaireRef` doit appartenir au foyer du payeur
 * identifié (RG-M4-02) — sinon fuite des données d'un tiers (ici, la famille Dupont de `CrmFixtures`).
 */
final class BeneficiaireProprieteTest extends BoutiqueApiTestCase
{
    public function testAjouterUneLigneAvecUnBeneficiaireDUnTiersEstRefuse(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        $beneficiaireDuTiers = $this->beneficiaireEnfantDupont();

        [$client, $panierId, $jeton] = $this->identifierParCompteDemo();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => [
                'produit' => (string) $produit->getId(),
                'quantite' => 1,
                'beneficiaireRef' => (string) $beneficiaireDuTiers->getId(),
            ],
        ]);
        self::assertResponseStatusCodeSame(403, 'RG-M4-02 : bénéficiaire d\'un tiers refusé à l\'ajout au panier.');
    }

    public function testAffecterUnBeneficiaireDUnTiersAUneLigneEstRefuse(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        $beneficiaireDuTiers = $this->beneficiaireEnfantDupont();

        [$client, $panierId, $jeton] = $this->identifierParCompteDemo();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1],
        ]);
        self::assertResponseIsSuccessful();
        $ligneId = (string) $reponse->toArray()['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireRef' => (string) $beneficiaireDuTiers->getId()]]],
        ]);
        self::assertResponseStatusCodeSame(403, 'RG-M4-02 : bénéficiaire d\'un tiers refusé à l\'étape bénéficiaires.');
    }

    private function beneficiaireEnfantDupont(): Beneficiaire
    {
        $enfant = $this->entite(Client::class, ['prenom' => CrmFixtures::ENFANT_PRENOM, 'nom' => 'Dupont']);

        return $this->entite(Beneficiaire::class, ['client' => $enfant]);
    }

    /** Ouvre un panier invité puis l'identifie sur le compte de démonstration (Camille Martin, sans famille). */
    private function identifierParCompteDemo(): array
    {
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'compte', 'email' => BoutiqueFixtures::CLIENT_EMAIL, 'motDePasse' => BoutiqueFixtures::CLIENT_MDP],
        ]);
        self::assertResponseIsSuccessful();

        return [$client, $panierId, $jeton];
    }
}
