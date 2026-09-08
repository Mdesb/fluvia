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
     */
    public function testLeRejeuNInterrogePasLeTerminal(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);

        $cle = (string) Uuid::v4();

        $accepte = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + [
            'json' => ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle],
        ])->toArray();
        self::assertSame('accepte', $accepte['statutTPE']);
        self::assertSame('0.00', $accepte['resteAPayer']);

        $rejeu = $client->request('POST', '/api/ventes/' . $venteId . '/paiements', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $idA, TpeMock::HEADER_SIMULATION => 'refuse'],
            'json' => ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle],
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
