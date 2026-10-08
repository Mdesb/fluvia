<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Enum\StatutAppairage;
use App\Vente\Tpe\TpeMock;

/**
 * Encaissement & validation : paiement scindé (CA-8), rendu espèces uniquement (CA-9), TPE (CA-10),
 * seuil d'impression (CA-11), appairage support (CA-12).
 */
final class PaiementTest extends VenteApiTestCase
{
    /** CA-8 / RG-M2-03 — Paiement scindé : reste dû décroît, validation seulement à reste = 0. */
    public function testCa8PaiementScinde(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']); // total 45,00

        // Validation impossible tant que reste dû > 0.
        $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + ['json' => []]);
        self::assertResponseStatusCodeSame(422);

        $p1 = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '20.00'],
        ])->toArray();
        self::assertSame('25.00', $p1['resteAPayer']);

        $p2 = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'cheque', 'montant' => '25.00'],
        ])->toArray();
        self::assertSame('0.00', $p2['resteAPayer']);

        $valide = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + ['json' => []])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('validee', $valide['statut']);
        self::assertCount(2, $valide['paiements'], 'Chaque moyen est journalisé.');
    }

    /** CA-9 / RG-M2-05 — Rendu de monnaie sur espèces uniquement ; aucun rendu sur CB/chèque. */
    public function testCa9RenduEspecesUniquement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        // Espèces 50 → rendu 5,00 sur une carte à 45.
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $especes = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '50.00'],
        ])->toArray();
        self::assertSame('5.00', $especes['rendu']);
        self::assertSame('0.00', $especes['resteAPayer']);

        // Sur une autre vente : chèque supérieur au dû → aucun rendu possible (422).
        [$venteId2] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteId2 . '/paiements', $entete + [
            'json' => ['moyen' => 'cheque', 'montant' => '50.00'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** CA-10 / US-L2-07 — TPE : accepté crée le règlement + réfTPE ; refus/timeout n'ajoute rien. */
    public function testCa10Tpe(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);

        // Refus TPE (simulé via en-tête) : aucun règlement, reste dû inchangé.
        $refus = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $idA, TpeMock::HEADER_SIMULATION => 'refuse'],
            'json' => ['moyen' => 'cb', 'montant' => '45.00'],
        ])->toArray();
        self::assertFalse($refus['reglementEnregistre']);
        self::assertSame('refuse', $refus['statutTPE']);
        self::assertSame('45.00', $refus['resteAPayer']);

        // Acceptation TPE (défaut) : règlement créé avec référence.
        $ok = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'cb', 'montant' => '45.00'],
        ])->toArray();
        self::assertTrue($ok['reglementEnregistre']);
        self::assertSame('accepte', $ok['statutTPE']);
        self::assertSame('0.00', $ok['resteAPayer']);
    }

    /**
     * Décision de Maxime du 07/10 — hors test et hors démonstration (`TPE_SIMULE_AUTORISE` non posé),
     * l'en-tête `X-Tpe-Simule` ne force rien : la carte est refusée en clair et rien n'est encaissé.
     * Le témoin passe par le câblage réel, pas par une instance construite à la main.
     */
    public function testSansTerminalConfigureLEnTeteNeForceRien(): void
    {
        $avant = $_ENV['TPE_SIMULE_AUTORISE'] ?? null;
        $_ENV['TPE_SIMULE_AUTORISE'] = $_SERVER['TPE_SIMULE_AUTORISE'] = '0';
        try {
            [$client, $entete, $idA] = $this->adminSurA();
            $session = $this->ouvrirSession($client, $entete);
            [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);

            $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', [
                'auth_bearer' => $entete['auth_bearer'],
                'headers' => [ContexteEtablissement::HEADER => $idA, TpeMock::HEADER_SIMULATION => 'accepte'],
                'json' => ['moyen' => 'cb', 'montant' => '45.00'],
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('Aucun terminal de paiement configuré', $reponse->getContent(false));

            $vente = $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray();
            self::assertSame('45.00', $vente['resteAPayer'], 'aucun règlement ne doit avoir été enregistré');
        } finally {
            $_ENV['TPE_SIMULE_AUTORISE'] = $_SERVER['TPE_SIMULE_AUTORISE'] = $avant;
        }
    }

    /** CA-11 — Seuil d'impression : au-dessus → impression auto ; en dessous → à la demande + renvoi. */
    public function testCa11SeuilImpression(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        // Vente carte 45,00 > seuil 20 → impression automatique.
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $valide = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + ['json' => []])->toArray();
        self::assertTrue($valide['imprime'], 'Total > seuil → impression automatique.');

        // Vente entrée 4,95 < seuil 20 → non imprimée automatiquement, renvoi proposé au client.
        $venteBasse = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteBasse['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $venteBasse['id'] . '/client', $entete + ['json' => ['recherche' => 'client@ex.com']]);
        $client->request('POST', '/api/ventes/' . $venteBasse['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '4.95']]);
        $valideBasse = $client->request('POST', '/api/ventes/' . $venteBasse['id'] . '/valider', $entete + ['json' => []])->toArray();
        self::assertFalse($valideBasse['imprime'], 'Total < seuil → pas d\'impression automatique.');

        // ⚠ CE TEST AFFIRMAIT `renvoye: true`. IL CONSACRAIT UN MENSONGE.
        //
        // Il n'existe dans tout le module ni expéditeur, ni passerelle SMS, ni événement : le mode
        // « renvoyer » se contentait de retourner « fait ». Un test vert qui garantit qu'une réponse
        // fausse le reste est la pire forme de couverture — il empêche la correction au lieu de
        // l'appeler.
        //
        // Et ce n'est PAS le transport nul (`MAILER_DSN=null://null`) : un DSN correct ne changerait
        // rien, il n'y a aucun code d'envoi à brancher dessus. Deux travaux, pas un.
        $client->request('POST', '/api/ventes/' . $venteBasse['id'] . '/ticket', $entete + ['json' => ['mode' => 'renvoyer', 'canal' => 'email']]);
        self::assertResponseStatusCodeSame(422, 'le renvoi refuse tant qu’aucun envoi n’existe, au lieu de dire « fait »');
    }

    /** CA-12 / RG-M2-04 — Appairage support : actif à la validation ; échec → remise bloquée. */
    public function testCa12AppairageSupport(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        // Succès : le support de la carte devient actif.
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $valide = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + ['json' => []])->toArray();
        self::assertNotEmpty($valide['supports']);
        self::assertSame(StatutAppairage::Actif->value, $valide['supports'][0]['statutAppairage']);

        // Code de support généré automatiquement (CA-12) : unique, non vide, signé HMAC.
        $identifiant = $valide['supports'][0]['identifiantSupport'];
        self::assertNotEmpty($identifiant, 'Un code de support doit être généré automatiquement sans override.');
        self::assertMatchesRegularExpression('/^CAR-[0-9A-HJKMNP-TV-Z]{16}-[0-9A-F]{10}$/', $identifiant);

        /** @var \App\Vente\Service\GenerateurCodeSupport $generateur */
        $generateur = static::getContainer()->get(\App\Vente\Service\GenerateurCodeSupport::class);
        self::assertTrue($generateur->verifier($identifiant), 'Le code généré doit être signé de manière vérifiable.');

        // Une seconde vente génère un code distinct (unicité, retry en cas de collision).
        [$venteId3] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteId3 . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $valide3 = $client->request('POST', '/api/ventes/' . $venteId3 . '/valider', $entete + ['json' => []])->toArray();
        self::assertNotSame($identifiant, $valide3['supports'][0]['identifiantSupport']);

        // Échec d'appairage simulé (identifiant préfixé ECHEC) : support non actif.
        [$venteId2, $ligneId2] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteId2 . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $valide2 = $client->request('POST', '/api/ventes/' . $venteId2 . '/valider', $entete + [
            'json' => ['supports' => [['ligne' => $ligneId2, 'identifiant' => 'ECHEC-CARTE']]],
        ])->toArray();
        self::assertSame(StatutAppairage::Echec->value, $valide2['supports'][0]['statutAppairage']);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array{0: string, 1: string} id vente, id ligne
     */
    private function venteCarteAvecLigne(object $client, array $entete, string $sessionId): array
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $apres = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ])->toArray();

        return [$vente['id'], $apres['lignes'][0]['id']];
    }
}
