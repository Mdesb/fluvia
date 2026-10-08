<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Sepa\Entity\MandatSepa;
use App\Tests\Boutique\BoutiqueApiTestCase;
use Doctrine\DBAL\Connection;

/**
 * BOUTIQUE ET ABONNEMENT EN LIGNE : SANS TAUX DE TVA, NI PAIEMENT NI MANDAT (D123, Q-B3 ; G-9, É18).
 *
 * La boutique crée ses lignes juste avant d'initier le paiement (`ConfirmerCommandeHandler`, appelé par
 * `PayerPanierProcessor`), l'abonnement en ligne juste après avoir signé le mandat. Une ligne sans taux
 * est refusée avant les deux. Les produits de la boutique de démonstration sont dans la catégorie que
 * `ComptaFixtures` met en correspondance à 20 % ; on la rend invalide en désactivant son taux.
 */
final class OnlineLineWithoutVatRefusalTest extends BoutiqueApiTestCase
{
    /** Panier payé sans taux : 422 qui nomme la catégorie, aucune commande, aucun paiement initié. Témoin : rétabli, il passe au paiement. */
    public function testACartWithoutRateInitiatesNoPayment(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];
        $panier = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1],
        ])->toArray();
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'invite', 'email' => 'invite.sans-taux@example.test'],
        ]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', [
            'headers' => $entete,
            'json' => ['mentionVersion' => 'mention-test'],
        ]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => (string) $panier['lignes'][0]['id'], 'beneficiaireSimple' => ['nom' => 'Dupont', 'prenom' => 'Jean', 'dateNaissance' => '1990-01-01']]]],
        ]);
        self::assertResponseIsSuccessful();
        $ventes = $this->compte('vente_vente');

        $this->rateActive(false);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(OffreFixtures::CAT_COMPTABLE, $client->getResponse()->getContent(false));
        $panierEntite = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertNull(
            $this->em()->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $panierEntite]),
            'Aucune commande, donc aucune initiation de paiement mémorisée.',
        );
        self::assertSame($ventes, $this->compte('vente_vente'));

        $this->rateActive(true);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseIsSuccessful('Témoin : la correspondance rétablie, le même panier passe au paiement.');
    }

    /** Abonnement en ligne sans taux : 422 qui nomme la catégorie, aucun mandat signé. Témoin : rétabli, il se souscrit. */
    public function testAnOnlineSubscriptionWithoutRateSignsNoMandate(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_ABONNEMENT_CODE]);
        $client = static::createClient();
        $token = $this->jeton($client, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);
        $ventes = $this->compte('vente_vente');

        $obtenu = [];
        foreach (['sans taux' => false, 'témoin' => true] as $cas => $actif) {
            $this->rateActive($actif);
            $client->request('POST', '/api/boutique/abonnements/souscrire', [
                'auth_bearer' => $token,
                'json' => [
                    'produit' => (string) $produit->getId(),
                    'iban' => 'FR7630006000011234567890189',
                    'bicDebiteur' => 'AGRIFRPP',
                    'debiteurNom' => 'Cas ' . $cas,
                ],
            ]);
            $obtenu[$cas] = [
                $client->getResponse()->getStatusCode(),
                \count($this->em()->getRepository(MandatSepa::class)->findBy(['debiteurNom' => 'Cas ' . $cas])),
                $this->compte('vente_vente') - $ventes,
            ];
            if (!$actif) {
                self::assertStringContainsString(OffreFixtures::CAT_COMPTABLE, $client->getResponse()->getContent(false));
            }
        }

        // [statut HTTP, mandats signés, ventes créées]
        self::assertSame(['sans taux' => [422, 0, 0], 'témoin' => [201, 1, 1]], $obtenu);
    }

    /** Le taux de la correspondance de `ComptaFixtures` (« Taux normal 20 % ») : inactif, la correspondance n'est plus valide. */
    private function rateActive(bool $actif): void
    {
        $this->db()->executeStatement(
            'UPDATE compta_taux_tva t JOIN compta_mapping_comptable m ON m.taux_tva_id = t.id SET t.actif = :a',
            ['a' => (int) $actif],
        );
    }

    private function compte(string $table): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function db(): Connection
    {
        /** @var Connection $connexion */
        $connexion = static::getContainer()->get('doctrine')->getConnection();

        return $connexion;
    }
}
