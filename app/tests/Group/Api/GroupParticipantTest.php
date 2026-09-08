<?php

declare(strict_types=1);

namespace App\Tests\Group\Api;

use App\Group\DataFixtures\GroupFixtures;
use App\Tests\Group\GroupApiTestCase;

/**
 * Liste nominative des participants d'un groupe : ajout, listing filtré, suppression — et la garde de
 * cloisonnement : on ne peut pas rattacher un membre à un groupe d'un autre établissement.
 */
final class GroupParticipantTest extends GroupApiTestCase
{
    public function testAjouterListerSupprimer(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $idGroupeA = $this->idGroupe(GroupFixtures::GROUPE_A_LABEL);

        // Un SECOND groupe sur le MÊME établissement, avec son propre membre : c'est lui qui rend le
        // filtre `?group=` observable. Un filtre muet (déclaré hors de `mapping.paths`) sortirait la
        // collection entière, et le décompte ci-dessous compterait aussi ce membre-là.
        $client->request('POST', '/api/participant_groups', $entete + [
            'json' => ['label' => 'Autre groupe A', 'organizerName' => 'X'],
        ]);
        self::assertResponseIsSuccessful();
        $idAutre = $client->getResponse()->toArray()['id'];
        $client->request('POST', '/api/group_participants', $entete + [
            'json' => ['group' => '/api/participant_groups/' . $idAutre, 'firstName' => 'Zoé', 'lastName' => 'Autre', 'category' => 'adult'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/group_participants', $entete + [
            'json' => [
                'group' => '/api/participant_groups/' . $idGroupeA,
                'firstName' => 'Nadia',
                'lastName' => 'Khelif',
                'category' => 'child',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $id = $client->getResponse()->toArray()['id'];

        // Le groupe A porte EXACTEMENT 3 membres (2 de démonstration + Nadia). Le filtre doit les
        // rendre exactement — jamais le 4e, qui appartient à l'autre groupe du même établissement.
        $client->request('GET', '/api/group_participants?group=' . $idGroupeA, $entete);
        self::assertResponseIsSuccessful();
        self::assertSame(3, self::total($client->getResponse()->toArray()), 'Le filtre ?group= doit discriminer, pas sortir la collection entière.');

        $client->request('DELETE', '/api/group_participants/' . $id, $entete);
        self::assertResponseStatusCodeSame(204);
    }

    public function testRattachementAUnGroupeEtrangerRefuse(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        // Groupe B (établissement B), invisible et interdit au gestionnaire de A.
        $idGroupeB = $this->idGroupe(GroupFixtures::GROUPE_B_LABEL);

        $client->request('POST', '/api/group_participants', $entete + [
            'json' => [
                'group' => '/api/participant_groups/' . $idGroupeB,
                'firstName' => 'Intrus',
                'lastName' => 'Test',
                'category' => 'adult',
            ],
        ]);
        // Cloisonnement en défense de profondeur : soit l'IRI hors périmètre ne se résout pas à la
        // désérialisation, soit le processeur la refuse en 404. Dans tous les cas : rien n'est créé.
        $code = $client->getResponse()->getStatusCode();
        self::assertGreaterThanOrEqual(400, $code, 'Rattacher un membre à un groupe étranger doit échouer.');
        self::assertLessThan(500, $code, 'Le refus doit être une erreur client, pas une 500.');
    }
}
