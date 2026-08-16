<?php

declare(strict_types=1);

namespace App\Tests\Musee\Api;

use App\Tests\Musee\MuseeApiTestCase;

final class SmokeTest extends MuseeApiTestCase
{
    public function testFixturesChargeesEtListeExpositions(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('GET', '/api/musee_expositions', $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertGreaterThanOrEqual(1, $donnees['totalItems'] ?? 0);
    }
}
