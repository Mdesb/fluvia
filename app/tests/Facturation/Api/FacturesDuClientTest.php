<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Tests\Facturation\FacturationApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * G-8 — la fiche d'un client montre SES factures, et seulement les siennes.
 *
 * ⚠ CE TEST EXISTE POUR UNE RAISON PRÉCISE : UNE LISTE FILTRÉE PEUT NE RIEN FILTRER DU TOUT.
 *
 * `GET /api/factures?destinataire.clientRef=…` répond 200 que le filtre soit déclaré ou non. Sans la
 * déclaration, il rend **toutes** les factures — et la fiche d'un client afficherait celles de tout le
 * monde, sous son nom. Le défaut a l'air de fonctionner parfaitement tant qu'il n'y a qu'un client en
 * base : c'est exactement pourquoi ce test en crée DEUX.
 */
final class FacturesDuClientTest extends FacturationApiTestCase
{
    public function testLaFicheDUnClientNeMontreQueSesFactures(): void
    {
        [$client, $entete] = $this->adminSurA();

        $clientA = Uuid::v4()->toRfc4122();
        $clientB = Uuid::v4()->toRfc4122();

        $factureA = $this->factureAvecClient($client, $entete, $clientA, 'Client A');
        $factureB = $this->factureAvecClient($client, $entete, $clientB, 'Client B');

        // Témoin d'assiette : sans lui, un filtre qui rendrait zéro résultat passerait aussi.
        $client->request('GET', '/api/factures', $entete);
        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(2, \count($client->getResponse()->toArray()['member']), 'Les deux factures existent.');

        $client->request('GET', '/api/factures/du-client?clientRef=' . $clientA, $entete);
        self::assertResponseIsSuccessful();
        $ids = array_map(static fn (array $f): string => $f['id'], $client->getResponse()->toArray()['member']);

        self::assertContains($factureA, $ids, 'La facture du client A doit remonter.');
        self::assertNotContains($factureB, $ids, 'Celle du client B ne doit PAS remonter — sinon le filtre ne filtre rien.');
        self::assertCount(1, $ids);
    }

    /** Un client sans facture rend une liste vide, pas la liste de tout le monde. */
    public function testUnClientSansFactureNeRemonteRien(): void
    {
        [$client, $entete] = $this->adminSurA();

        $this->factureAvecClient($client, $entete, Uuid::v4()->toRfc4122(), 'Quelqu\'un');

        $client->request('GET', '/api/factures/du-client?clientRef=' . Uuid::v4()->toRfc4122(), $entete);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getResponse()->toArray()['member']);
    }

    private function factureAvecClient(object $client, array $entete, string $clientRef, string $nom): string
    {
        $corps = $this->corpsFactureDirecte(100.0);
        $corps['destinataire']['clientRef'] = $clientRef;
        $corps['destinataire']['raisonSociale'] = $nom;

        $client->request('POST', '/api/factures', $entete + ['json' => $corps]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
