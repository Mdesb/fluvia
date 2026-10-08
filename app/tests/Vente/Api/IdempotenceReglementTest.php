<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Tpe\TpeMock;
use Symfony\Component\Uid\Uuid;

/**
 * REJOUER UN RÈGLEMENT N'ENCAISSE PAS DEUX FOIS.
 *
 * `Vente` porte une clé d'idempotence depuis l'origine ; le RÈGLEMENT n'en avait aucune, alors que
 * c'est lui qui déplace l'argent. nginx coupe la requête à 60 s, une transaction carte réelle dépasse
 * ce délai : la réponse se perd et le client rejoue.
 *
 * ── ⚠ LE TÉMOIN QUI COMPTE N'EST PAS « LE RESTE DÛ N'A PAS BOUGÉ » ─────────────────────────────
 *
 * Deux mécanismes différents produisent ce même reste dû : la garde qui court-circuite avant le
 * terminal, et un second appel au terminal qui se ferait refuser. Le test qui n'observe que le
 * montant ne les distingue pas, et resterait vert si la garde disparaissait.
 *
 * `testLeRejeuNInterrogePasLeTerminal` sépare les deux : le rejeu porte l'en-tête qui FORCE un refus.
 * Une réponse « accepté » prouve alors que le terminal n'a pas été sollicité — c'est la seule lecture
 * possible. Sans la garde, ce test répond « refusé » et tombe.
 *
 * ── LA PORTÉE DE LA CLÉ : TOUTE LA TABLE (G-1, G-4 de la spec du ticket opposable) ─────────────
 *
 * Une clé (ou un `id`) déjà employée sur une AUTRE vente, ou sur cette vente avec un autre moyen ou
 * un autre montant, est refusée avant tout effet. Le pendant PMV, dont le témoin est le solde, est
 * dans `App\Tests\Crm\Api\PmvIdempotenceTest` (il lui faut les fixtures du CRM).
 */
final class IdempotenceReglementTest extends VenteApiTestCase
{
    /** Deux fois la même clé sur la même vente : un seul règlement, reste dû inchangé. */
    public function testUnRejeuNEncaissePasDeuxFois(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']); // total 45,00

        $cle = (string) Uuid::v4();
        $corps = ['json' => ['moyen' => 'especes', 'montant' => '20.00', 'cleIdempotence' => $cle]];

        $premier = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + $corps)->toArray();
        self::assertTrue($premier['reglementEnregistre']);
        self::assertSame('25.00', $premier['resteAPayer']);

        $rejeu = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + $corps)->toArray();
        self::assertSame('25.00', $rejeu['resteAPayer'], 'Le rejeu ne doit rien encaisser de plus.');
        self::assertSame($premier['paiement'], $rejeu['paiement'], 'Le rejeu rend LE règlement déjà enregistré.');
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($rejeu['dejaEnregistre']);

        // Le compte fait foi, pas seulement le montant : on solde et on valide.
        $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '25.00'],
        ]);
        $valide = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + ['json' => []])->toArray();
        self::assertCount(2, $valide['paiements'], 'Trois appels, deux règlements : le rejeu n\'en a pas créé un troisième.');
    }

    /**
     * Le rejeu court-circuite AVANT le terminal.
     *
     * Le second appel force un refus par en-tête. S'il répond « accepté », c'est que le terminal n'a
     * pas été interrogé — aucune autre explication ne tient.
     *
     * Le montant est PARTIEL (20 sur 45), et c'est ce qui rend le témoin valable : à 45 sur 45, le
     * rejeu sans garde n'atteignait jamais le terminal — il tombait sur « aucun rendu de monnaie »,
     * et le test aurait été rouge sans rien dire du terminal.
     */
    public function testLeRejeuNInterrogePasLeTerminal(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);

        $cle = (string) Uuid::v4();

        $accepte = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'cb', 'montant' => '20.00', 'cleIdempotence' => $cle],
        ])->toArray();
        self::assertSame('accepte', $accepte['statutTPE']);
        self::assertSame('25.00', $accepte['resteAPayer']);

        $rejeu = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $idA, TpeMock::HEADER_SIMULATION => 'refuse'],
            'json' => ['moyen' => 'cb', 'montant' => '20.00', 'cleIdempotence' => $cle],
        ])->toArray();

        self::assertTrue($rejeu['reglementEnregistre'], 'Un refus ici signifierait que la carte est repassée.');
        self::assertSame('accepte', $rejeu['statutTPE']);
        self::assertSame($accepte['paiement'], $rejeu['paiement']);
    }

    /**
     * L'identifiant fourni vaut clé, lui aussi.
     *
     * `id` existait déjà pour le rejeu hors-ligne et n'était protégé que par la clé primaire : le
     * doublon était refusé au `flush()`, donc APRÈS que la carte avait été débitée.
     */
    public function testUnIdentifiantFourniVautCle(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);

        $id = (string) Uuid::v4();
        $corps = ['json' => ['moyen' => 'especes', 'montant' => '20.00', 'id' => $id]];

        $premier = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + $corps)->toArray();
        self::assertSame($id, $premier['paiement']);

        $rejeu = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + $corps)->toArray();
        self::assertSame('25.00', $rejeu['resteAPayer'], 'Le même identifiant ne crée pas un second règlement.');
        self::assertSame($id, $rejeu['paiement']);
        self::assertTrue($rejeu['dejaEnregistre']);
    }

    /**
     * Sans clé, rien ne change — le témoin que la garde ÉPARGNE.
     *
     * Un paiement scindé légitime envoie deux fois le même moyen et le même montant. Si la garde
     * mordait sur la ressemblance plutôt que sur la clé, elle refuserait la seconde moitié d'un
     * encaissement parfaitement normal, et les tests de rejeu resteraient verts.
     */
    public function testSansCleDeuxReglementsIdentiquesRestentDeux(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);

        $moitie = ['json' => ['moyen' => 'especes', 'montant' => '22.50']];

        $un = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + $moitie)->toArray();
        self::assertSame('22.50', $un['resteAPayer']);

        $deux = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + $moitie)->toArray();
        self::assertSame('0.00', $deux['resteAPayer'], 'Deux règlements identiques sans clé restent deux règlements.');
        self::assertNotSame($un['paiement'], $deux['paiement']);
    }

    /** Le même témoin, avec des clés : deux règlements identiques et DEUX clés restent deux. */
    public function testDeuxClesDeuxReglementsIdentiquesRestentDeux(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);

        foreach ([Uuid::v4(), Uuid::v4()] as $cle) {
            $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
                'json' => ['moyen' => 'especes', 'montant' => '22.50', 'cleIdempotence' => (string) $cle],
            ]);
            self::assertResponseStatusCodeSame(201);
        }

        self::assertCount(2, $this->paiementsDe($client, $entete, $venteId));
    }

    /**
     * Le rejeu se juge AVANT le 409 « vente validée ».
     *
     * Le règlement qui soldait la vente est enregistré, sa réponse se perd, la vente est validée : le
     * rejeu doit rendre ce règlement, pas une erreur qui ferait croire qu'il n'est pas passé.
     */
    public function testLeRejeuApresValidationRendLeReglementDOrigine(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);

        $corps = ['json' => ['moyen' => 'especes', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()]];
        $premier = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + $corps)->toArray();
        $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $rejeu = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + $corps);
        self::assertSame(200, $rejeu->getStatusCode(), 'Un rejeu après validation n\'est pas un 409 « encaissement clos ».');
        self::assertTrue($rejeu->toArray()['dejaEnregistre']);
        self::assertSame($premier['paiement'], $rejeu->toArray()['paiement']);
        self::assertCount(1, $this->paiementsDe($client, $entete, $venteId));
    }

    /**
     * Même clé, autre montant ou autre moyen : refus explicite, rien d'encaissé (G-4).
     *
     * Rendre l'ancien règlement en silence ferait croire à l'appelant que SA demande est passée.
     * Le second appel en carte porte l'en-tête qui force un REFUS du terminal : un 422 ne peut donc
     * venir que de la garde, placée avant le terminal — sollicité, il aurait répondu 200 « refusé ».
     */
    public function testMemeCleAutreContenuEstRefusee(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $cle = (string) Uuid::v4();

        $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '20.00', 'cleIdempotence' => $cle],
        ]);
        self::assertResponseStatusCodeSame(201);

        $autreMontant = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '25.00', 'cleIdempotence' => $cle],
        ]);
        self::assertSame(422, $autreMontant->getStatusCode(), 'Même clé, autre montant : refus.');

        $autreMoyen = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $idA, TpeMock::HEADER_SIMULATION => 'refuse'],
            'json' => ['moyen' => 'cb', 'montant' => '20.00', 'cleIdempotence' => $cle],
        ]);
        self::assertSame(422, $autreMoyen->getStatusCode(), 'Même clé, autre moyen : refus, avant le terminal.');

        self::assertCount(1, $this->paiementsDe($client, $entete, $venteId), 'Aucun des deux refus n\'a encaissé.');
    }

    /**
     * Une clé déjà employée sur une AUTRE vente est refusée avant tout effet.
     *
     * La recherche dans la seule vente ne la trouvait pas : le terminal était sollicité, puis l'index
     * unique refusait l'écriture au `flush()` — en 500, la carte déjà débitée. L'en-tête force un
     * REFUS du terminal : un 422 ne peut venir que d'une garde placée avant lui.
     */
    public function testUneCleDUneAutreVenteEstRefuseeAvantToutEffet(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$premiere] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        [$seconde] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $cle = (string) Uuid::v4();

        $client->request('POST', '/api/ventes/' . $premiere . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '20.00', 'cleIdempotence' => $cle],
        ]);
        self::assertResponseStatusCodeSame(201);

        $refus = $client->request('POST', '/api/ventes/' . $seconde . '/paiements', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $idA, TpeMock::HEADER_SIMULATION => 'refuse'],
            'json' => ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle],
        ]);
        self::assertSame(422, $refus->getStatusCode(), 'La clé d\'une autre vente est refusée, pas rejouée ni acceptée.');
        self::assertCount(0, $this->paiementsDe($client, $entete, $seconde));
        self::assertCount(1, $this->paiementsDe($client, $entete, $premiere), 'La vente d\'origine garde son règlement.');
    }

    /** Un `id` déjà pris par une autre vente : même refus, avant l'effet et non plus au `flush()`. */
    public function testUnIdentifiantDUneAutreVenteEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$premiere] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        [$seconde] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $corps = ['json' => ['moyen' => 'especes', 'montant' => '20.00', 'id' => (string) Uuid::v4()]];

        $client->request('POST', '/api/ventes/' . $premiere . '/paiements', $entete + $corps);
        self::assertResponseStatusCodeSame(201);

        $refus = $client->request('POST', '/api/ventes/' . $seconde . '/paiements', $entete + $corps);
        self::assertSame(422, $refus->getStatusCode());
        self::assertCount(0, $this->paiementsDe($client, $entete, $seconde));
    }

    /**
     * Une clé illisible, vide ou constante (UUID nul, UUID max) est refusée : l'ignorer laisserait
     * croire le règlement rejouable, et une clé constante heurterait les règlements de tous.
     */
    public function testUneCleIllisibleOuConstanteEstRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);

        foreach (['pas-une-cle', '', '00000000-0000-0000-0000-000000000000', 'ffffffff-ffff-ffff-ffff-ffffffffffff'] as $cle) {
            $refus = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
                'json' => ['moyen' => 'especes', 'montant' => '20.00', 'cleIdempotence' => $cle],
            ]);
            self::assertSame(422, $refus->getStatusCode(), sprintf('Clé « %s » : refus attendu.', $cle));
        }
        self::assertCount(0, $this->paiementsDe($client, $entete, $venteId));
    }

    /** La clé se reconnaît quelle que soit sa graphie (majuscules), le montant quel que soit son format. */
    public function testLeRejeuIgnoreLaGraphieDeLaCleEtDuMontant(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $cle = (string) Uuid::v4();

        $premier = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '20.00', 'cleIdempotence' => $cle],
        ])->toArray();
        $rejeu = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => 20, 'cleIdempotence' => strtoupper($cle)],
        ])->toArray();

        self::assertTrue($rejeu['dejaEnregistre']);
        self::assertSame($premier['paiement'], $rejeu['paiement']);
        self::assertCount(1, $this->paiementsDe($client, $entete, $venteId));
    }

    /** Une clé et un `id` qui désignent deux choses différentes : refus, dans les deux sens. */
    public function testCleEtIdentifiantContradictoiresSontRefuses(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $cle = (string) Uuid::v4();
        $id = (string) Uuid::v4();

        $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '20.00', 'cleIdempotence' => $cle, 'id' => $id],
        ]);
        self::assertResponseStatusCodeSame(201);

        foreach ([['cleIdempotence' => $cle, 'id' => (string) Uuid::v4()], ['cleIdempotence' => (string) Uuid::v4(), 'id' => $id]] as $autre) {
            $refus = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
                'json' => ['moyen' => 'especes', 'montant' => '20.00'] + $autre,
            ]);
            self::assertSame(422, $refus->getStatusCode());
        }
        self::assertCount(1, $this->paiementsDe($client, $entete, $venteId));
    }

    /**
     * La synchronisation hors-ligne reste SANS clé de règlement (Q-A2, lot dédié).
     *
     * Deux paiements d'une même opération portant la même clé passaient avant ce lot (la clé était
     * ignorée) ; contrôlée et enregistrée, elle enverrait l'opération en quarantaine au `flush()`.
     */
    public function testLaSynchronisationHorsLigneIgnoreLaCleDesPaiements(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $cle = (string) Uuid::v4();

        $reponse = $client->request('POST', '/api/synchro/operations', $entete + ['json' => [
            'session' => '/api/session_caisses/' . $session['id'],
            'operations' => [[
                'cleIdempotence' => (string) Uuid::v4(),
                'sequenceLocale' => 1,
                'lignes' => [[
                    'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                    'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                    'quantite' => 1,
                ]],
                'paiements' => [
                    ['moyen' => 'especes', 'montant' => '22.50', 'cleIdempotence' => $cle],
                    ['moyen' => 'especes', 'montant' => '22.50', 'cleIdempotence' => $cle],
                ],
            ]],
        ]])->toArray();

        self::assertEmpty($reponse['quarantaine']);
        self::assertCount(1, $reponse['inseres']);
        self::assertCount(2, $this->paiementsDe($client, $entete, $reponse['inseres'][0]));
    }

    /**
     * Les règlements enregistrés, relus en base par l'API — pas la réponse de l'appel qu'on juge.
     *
     * @param array<string, mixed> $entete
     *
     * @return list<array<string, mixed>>
     */
    private function paiementsDe(object $client, array $entete, string $venteId): array
    {
        return $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray()['paiements'];
    }

    /**
     * Une vente à 45,00 avec une ligne — même montage que `PaiementTest`, dont c'est déjà un
     * assistant privé. Je le recopie plutôt que de le remonter dans `VenteApiTestCase` : cette
     * classe de base est partagée par toutes les suites du module, et la modifier pendant que
     * d'autres sessions travaillent dessus coûterait plus qu'un doublon de fixture.
     *
     * @param array<string, mixed> $entete
     *
     * @return array{0: string, 1: string}
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
