<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;

/**
 * CQ-8 (ARGENT) — émission multiple : une ligne émettrice de support à `quantite = N` doit émettre N
 * `BilletSupport`, chacun avec son identifiant propre (RG-CQ8-01). Défaut préexistant révélé par CQ-1 :
 * la vente facturait N (prix × quantité, scellé `qte`=N) mais n'émettait qu'un seul support. Un
 * identifiant explicite impose `quantite = 1` (RG-CQ8-02).
 *
 * CA-4 (non-régression sensible : recharge d'une carte existante à quantité > 1 doit sortir par le
 * refus RG-CQ1-07, jamais par le nouveau RG-CQ8-02) est déjà couvert, avec le harnais recharge
 * complet (émission + appairage), par `App\Tests\Acces\Api\CardRechargeTest::testRefusRechargeQuantiteSuperieureAUn`.
 */
final class EmissionMultipleTest extends VenteApiTestCase
{
    /** RG-CQ8-01 — un billet vendu en quantité 3 émet 3 supports distincts (le client paie 3, reçoit 3). */
    public function testEmissionBilletQuantiteTroisEmetTroisSupportsDistincts(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 3,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '16.50']]);

        $valide = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []])->toArray();
        self::assertResponseIsSuccessful();

        self::assertCount(3, $valide['supports'], 'RG-CQ8-01 : 3 supports pour une ligne quantite 3.');
        $identifiants = array_map(static fn (array $s): string => $s['identifiantSupport'], $valide['supports']);
        self::assertCount(3, array_unique($identifiants), 'Chaque support a un identifiant unique distinct.');
    }

    /**
     * RG-CQ8-01 (cœur ARGENT) — deux cartes multi-entrées vendues en une ligne quantité 2 : deux
     * supports, chacun crédité du stock initial complet (12), pas un seul chargé pour un paiement double.
     */
    public function testEmissionCarteQuantiteDeuxEmetDeuxSupportsChacunCredite(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 2,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '90.00']]);

        $valide = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []])->toArray();
        self::assertResponseIsSuccessful();

        self::assertCount(2, $valide['supports'], 'RG-CQ8-01 : 2 cartes vendues -> 2 supports emis.');
        foreach ($valide['supports'] as $support) {
            self::assertSame(12, $support['nbCompostages'], 'Chaque carte porte le stock initial complet (12), jamais divise.');
        }
        $identifiants = array_map(static fn (array $s): string => $s['identifiantSupport'], $valide['supports']);
        self::assertCount(2, array_unique($identifiants), 'Deux identifiants de carte distincts.');
    }

    /** RG-CQ8-02 — un identifiant de support explicite en émission impose quantite = 1 (422). */
    public function testIdentifiantExpliciteAvecQuantiteSuperieureAUnRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        $vente = $this->creerVente($client, $entete, $session['id']);
        $apres = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 2,
            ],
        ])->toArray();
        $ligneId = $apres['lignes'][0]['id'];
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '11.00']]);

        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + [
            'json' => ['supports' => [['ligne' => $ligneId, 'identifiant' => 'EXPL-CQ8-0001']]],
        ]);
        self::assertSame(422, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /** Non-régression RG-CQ8-01 — quantité 1 : exactement un support, comportement inchangé. */
    public function testEmissionQuantiteUnInchangee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '5.50']]);

        $valide = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []])->toArray();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $valide['supports'], 'Non-regression : quantite 1 -> un seul support.');
    }
}
