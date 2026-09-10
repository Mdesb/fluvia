<?php

declare(strict_types=1);

namespace App\Tests\Group\Api;

use App\Group\DataFixtures\GroupFixtures;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Group\GroupApiTestCase;

/**
 * CRUD d'un `ParticipantGroup` : création, liste, lecture, modification — et la garde de permission
 * (`group.manage`) + l'estampillage serveur de l'établissement (D41).
 */
final class CrudParticipantGroupTest extends GroupApiTestCase
{
    public function testCreerListerLireModifier(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();

        // Établissement attendu = celui du groupe de démonstration (établissement actif A).
        $client->request('GET', '/api/participant_groups/' . $this->idGroupe(GroupFixtures::GROUPE_A_LABEL), $entete);
        self::assertResponseIsSuccessful();
        $etabAttendu = $client->getResponse()->toArray()['etablissement'];

        // Création — l'établissement n'est pas dans le corps : il est posé par le serveur.
        $client->request('POST', '/api/participant_groups', $entete + [
            'json' => [
                'label' => 'Tour-opérateur Alpes',
                'type' => 'tour',
                'organizerName' => 'Agence Sommets',
                'organizerEmail' => 'contact@sommets.example',
                'headcount' => 45,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $cree = $client->getResponse()->toArray();
        self::assertSame('Tour-opérateur Alpes', $cree['label']);
        self::assertSame('tour', $cree['type']);
        self::assertSame($etabAttendu, $cree['etablissement'], 'L\'établissement doit être estampillé sur l\'actif (A).');
        $id = $cree['id'];

        // Liste : le nouveau groupe et celui de démonstration y sont.
        $client->request('GET', '/api/participant_groups', $entete);
        self::assertResponseIsSuccessful();
        $labels = array_column(self::membres($client->getResponse()->toArray()), 'label');
        self::assertContains('Tour-opérateur Alpes', $labels);
        self::assertContains(GroupFixtures::GROUPE_A_LABEL, $labels);

        // Lecture item.
        $client->request('GET', '/api/participant_groups/' . $id, $entete);
        self::assertResponseIsSuccessful();

        // Modification.
        $client->request('PATCH', '/api/participant_groups/' . $id, $this->entetePatch($entete) + [
            'json' => ['headcount' => 50],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(50, $client->getResponse()->toArray()['headcount']);
    }

    public function testCreationRefuseeSansPermissionManage(): void
    {
        // L'agent d'accueil réservation possède `reservation.*` mais aucune permission `group.*`.
        $client = static::createClient();
        $token = $this->jeton($client, ReservationFixtures::AGENT_EMAIL, ReservationFixtures::AGENT_MDP);
        $idA = $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $client->request('POST', '/api/participant_groups', $entete + [
            'json' => ['label' => 'Interdit', 'organizerName' => 'X'],
        ]);
        self::assertResponseStatusCodeSame(403, 'group.manage est requis pour créer un groupe.');
    }
}
