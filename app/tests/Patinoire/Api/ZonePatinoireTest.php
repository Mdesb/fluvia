<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Api;

use App\Tests\Patinoire\PatinoireApiTestCase;

/**
 * Comptage glace / gradins (US-PATIN-08, RG-PAT-02, CA-8). La patinoire **configure** deux
 * `EspaceAcces` L3 distincts sans ajouter de mécanisme de routage/jauge nouveau (§4.7) : le moteur de
 * jauge FMI lui-même (incrément/décrément par passage) est générique et déjà couvert par la suite de
 * tests L3 (`tests/Acces`) — ce test vérifie la **spécificité patinoire** : deux zones indépendamment
 * configurées (seuil propre, routage par `typeZone`), sans lien de dépendance entre les deux.
 */
final class ZonePatinoireTest extends PatinoireApiTestCase
{
    public function testDeuxZonesJaugesIndependantes(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('GET', '/api/patinoire_zone_patinoires', $entete);
        self::assertResponseIsSuccessful();
        $zones = $client->getResponse()->toArray()['member'];
        self::assertCount(2, $zones, 'RG-PAT-02 : deux zones distinctes configurées (glace, gradins).');

        $typesZone = array_map(static fn (array $z): string => $z['typeZone'], $zones);
        self::assertContains('glace', $typesZone);
        self::assertContains('gradins', $typesZone);

        $espacesAcces = array_map(static fn (array $z): string => $z['espaceAcces'], $zones);
        self::assertCount(2, array_unique($espacesAcces), 'Chaque zone référence un EspaceAcces L3 distinct (jauges indépendantes).');
    }

    public function testDeuxZonesNePeuventPasPartagerLeMemeEspaceAcces(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('GET', '/api/patinoire_zone_patinoires', $entete);
        $espaceAccesGlace = $client->getResponse()->toArray()['member'][0]['espaceAcces'];

        $client->request('POST', '/api/patinoire_zone_patinoires', $entete + [
            'json' => ['espaceAcces' => $espaceAccesGlace, 'typeZone' => 'gradins'],
        ]);
        self::assertResponseStatusCodeSame(422, 'Un EspaceAcces ne peut porter qu\'une seule ZonePatinoire (contrainte unique).');
    }

    public function testConfigurationReserveeAuGestionnaireOffre(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $client->request('GET', '/api/patinoire_zone_patinoires', $entete);
        $espaceAccesGlace = $client->getResponse()->toArray()['member'][0]['espaceAcces'];

        $client->request('POST', '/api/patinoire_zone_patinoires', $entete + [
            'json' => ['espaceAcces' => $espaceAccesGlace, 'typeZone' => 'gradins'],
        ]);
        self::assertResponseStatusCodeSame(403, 'patinoire.configurer requis (agent de comptoir non habilité).');
    }
}
