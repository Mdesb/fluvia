<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Tests\Acces\AccesApiTestCase;

/**
 * Journal des passages (US-L3-11, écran A-05, RG-ACC-06, CA-12) : chaque passage horodaté et
 * rattaché, filtrable (période/espace/équipement/type), exportable, lecture seule ; un passage
 * hors-ligne apparaît après synchro sans doublon.
 */
final class JournalTest extends AccesApiTestCase
{
    public function testCa12PassageHorodateEtRattache(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/passages', $entete);
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        self::assertCount(1, $membres);
        $passage = $membres[0];
        self::assertNotEmpty($passage['horodatage']);
        self::assertNotEmpty($passage['espace']);
        self::assertNotEmpty($passage['equipement']);
        self::assertNotEmpty($passage['support']);
        self::assertSame('valide', $passage['resultat']);
    }

    public function testCa12ExportFiltreParEspaceEtResultat(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);

        $client->request('GET', '/api/acces/passages/export', $entete + [
            'query' => ['espace' => $this->idEspaceAcces(), 'resultat' => 'valide'],
        ]);
        self::assertResponseIsSuccessful();
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        self::assertCount(1, $membres);
    }

    public function testCa12JournalLectureSeule(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);
        $passageId = $client->getResponse()->toArray()['id'];

        // Aucune opération d'écriture (PATCH/DELETE) exposée sur le journal.
        $client->request('PATCH', '/api/passages/' . $passageId, $entete + [
            'json' => ['motif' => 'falsification'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);

        $client->request('DELETE', '/api/passages/' . $passageId, $entete);
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);
    }
}
