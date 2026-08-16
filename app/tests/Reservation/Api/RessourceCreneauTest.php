<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\DataFixtures\ReservationFixtures;
use App\Tests\Reservation\ReservationApiTestCase;

/** Ressource générique et chevauchement de créneau (US-RES-01, RG-M5-03/05). */
final class RessourceCreneauTest extends ReservationApiTestCase
{
    public function testCa1CreationRessourceTypeParametrable(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();

        $client->request('POST', '/api/reservation_ressources', $entete + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM),
                'codeType' => 'table',
                'libelle' => 'Table musée n°1',
                'capacitePropre' => 6,
                'competenceRequise' => null,
            ],
        ]);

        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('table', $donnees['codeType'], 'CA-1 : le type de ressource est une valeur de configuration libre.');
        self::assertSame(6, $donnees['capacitePropre']);
    }

    public function testTypeRessourceInvalideRefuse(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();

        $client->request('POST', '/api/reservation_ressources', $entete + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM),
                'codeType' => 'INVALIDE TYPE !!',
                'libelle' => 'Ressource invalide',
                'capacitePropre' => 1,
            ],
        ]);

        self::assertResponseStatusCodeSame(422, 'Le codeType doit respecter le référentiel extensible (snake_case).');
    }

    public function testCa2ChevauchementBloque(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();
        $terrain = '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE);

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => $terrain,
                'debut' => '2026-09-01T10:00:00+00:00',
                'fin' => '2026-09-01T11:00:00+00:00',
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();

        // Fenêtre chevauchante (10h30-11h30) sur la même ressource.
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => $terrain,
                'debut' => '2026-09-01T10:30:00+00:00',
                'fin' => '2026-09-01T11:30:00+00:00',
                'capacite' => 4,
            ],
        ]);
        self::assertResponseStatusCodeSame(409, 'CA-2 : conflit de ressource bloqué avec motif explicite.');
    }

    public function testChevauchementNonBloqueSurFenetresDisjointes(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();
        $terrain = '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE);

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => ['ressource' => $terrain, 'debut' => '2026-09-02T10:00:00+00:00', 'fin' => '2026-09-02T11:00:00+00:00', 'capacite' => 4],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => ['ressource' => $terrain, 'debut' => '2026-09-02T11:00:00+00:00', 'fin' => '2026-09-02T12:00:00+00:00', 'capacite' => 4],
        ]);
        self::assertResponseIsSuccessful();
    }
}
