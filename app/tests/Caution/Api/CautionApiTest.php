<?php

declare(strict_types=1);

namespace App\Tests\Caution\Api;

use App\Caution\DataFixtures\CautionFixtures;
use App\Tests\Caution\CautionApiTestCase;

/** Lecture cross-verticale du module socle `App\Caution` (dashboard, `caution.piloter`). */
final class CautionApiTest extends CautionApiTestCase
{
    public function testCollectionExposeLaCautionDeDemonstration(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/cautions', $entete);
        self::assertResponseIsSuccessful();
        $membres = $client->getResponse()->toArray()['member'];
        self::assertNotEmpty($membres);
        $demo = array_values(array_filter($membres, static fn (array $c) => $c['referenceCible'] === CautionFixtures::REFERENCE_CIBLE_DEMO));
        self::assertNotEmpty($demo);
        self::assertSame('consignee', $demo[0]['statut']);
        self::assertSame(1000, $demo[0]['montantCentimes']);
    }

    public function testAccesRefuseSansPermission(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, \App\DataFixtures\SocleFixtures::LECTEUR_EMAIL, \App\DataFixtures\SocleFixtures::LECTEUR_MDP);
        $client->request('GET', '/api/cautions', ['auth_bearer' => $token]);
        self::assertResponseStatusCodeSame(403, 'caution.piloter requis, non couvert par *.lire (RG-SOCLE-04).');
    }
}
