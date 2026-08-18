<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;

/**
 * Session de caisse & régie : ouverture/unicité (CA-1), réouverture d'une caisse sécurisée (CA-2),
 * mouvements d'espèces (US-L2-10), clôture Z (CA-14).
 */
final class SessionTest extends VenteApiTestCase
{
    /** CA-1 / RG-M2-01 — Ouverture mémorise PDV+fond+régisseur+date ; une seule session active/PDV. */
    public function testCa1OuvertureEtUniciteSession(): void
    {
        [$client, $entete] = $this->adminSurA();

        $session = $this->ouvrirSession($client, $entete, '80.00');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('ouverte', $session['etat']);
        self::assertSame('80.00', $session['fondDeCaisse']);
        self::assertArrayHasKey('ouvertureLe', $session);
        self::assertNotEmpty($session['numero']);

        // Deuxième session sur le même point de vente : refusée (unicité).
        $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + [
            'json' => [
                'pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente(),
                'caisse' => '/api/caisses/' . $this->idCaisse(),
                'regisseur' => '/api/utilisateurs/' . $this->idAdmin(),
                'codeRegisseur' => 'CODE-REGIE-2026',
                'fondDeCaisse' => '10.00',
            ],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    /** CA-1 — Ouverture : le fond est requis, mais PAS le code régisseur (code réservé aux manips sensibles). */
    public function testCa1OuvertureExigeFondPasDeCodeRegisseur(): void
    {
        [$client, $entete] = $this->adminSurA();
        $base = [
            'pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente(),
            'caisse' => '/api/caisses/' . $this->idCaisse(),
        ];

        // Sans fond de caisse : refusée (422).
        $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + ['json' => $base]);
        self::assertResponseStatusCodeSame(422);

        // Avec fond mais SANS code régisseur ni régisseur explicite : acceptée ;
        // l'opérateur connecté devient régisseur par défaut (même sur une caisse fermée « securisee »).
        $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + [
            'json' => $base + ['fondDeCaisse' => '10.00'],
        ]);
        self::assertResponseIsSuccessful();
    }

    /** CA-1 — Une vente est impossible sur une session close (hors session ouverte). */
    public function testVenteHorsSessionOuverteRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $this->cloturer($client, $entete, $session['id']);

        $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $session['id']],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    /** CA-2 — Réouverture d'une caisse sécurisée : exige le code régisseur. */
    public function testCa2ReouvertureCaisseSecuriseeExigeCode(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $this->cloturer($client, $entete, $session['id']);

        // Sans code : refusée.
        $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/rouvrir', $entete + ['json' => []]);
        self::assertResponseStatusCodeSame(422);

        // Avec code : acceptée.
        $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/rouvrir', $entete + [
            'json' => ['codeRegisseur' => 'CODE-REGIE-2026'],
        ]);
        self::assertResponseIsSuccessful();
    }

    /** CA-14 / RG-M2-06 — Clôture Z totalise, calcule l'écart, fige la session ; 2e clôture refusée. */
    public function testCa14ClotureZ(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete, '50.00');

        // Une vente validée en espèces de 5,50 €.
        $this->vendreEntree($client, $entete, $session['id']);

        // L'entrée (5,50 €) bénéficie de la promo guichet -10% → 4,95 € encaissés (rendu sur espèces).
        $z = $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $entete + [
            'json' => ['comptages' => [['moyen' => 'especes', 'compte' => '54.95']], 'versement' => '0.00'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('close', $z['etatSession']);
        self::assertSame('4.95', $z['totalVentes']);
        self::assertArrayHasKey('etatDeRegie', $z);
        // Théorique espèces = fond 50 + 4,95 encaissé = 54,95 ; compté 54,95 → écart nul.
        self::assertSame('0.00', $z['ecartTotal']);

        // Clôture irréversible : 2e tentative refusée.
        $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $entete + [
            'json' => ['comptages' => []],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    /** US-L2-10 — Mouvement d'espèces (gros retrait) enregistré avec alerte régisseur. */
    public function testMouvementCaisseAlerteGrosRetrait(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        $mouvement = $client->request('POST', '/api/mouvements-caisse', $entete + [
            'json' => [
                'session' => '/api/session_caisses/' . $session['id'],
                'type' => 'retrait',
                'montant' => '250.00',
                'motif' => 'Retrait sécurité coffre',
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($mouvement['alerteRegisseur']);
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function cloturer(object $client, array $entete, string $sessionId): void
    {
        $client->request('POST', '/api/sessions-caisse/' . $sessionId . '/cloturer', $entete + [
            'json' => ['comptages' => []],
        ]);
    }

    /**
     * Vend et valide une entrée unitaire (5,50 €) réglée en espèces.
     *
     * @param array<string, mixed> $entete
     */
    private function vendreEntree(object $client, array $entete, string $sessionId): void
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '5.50'],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
    }
}
