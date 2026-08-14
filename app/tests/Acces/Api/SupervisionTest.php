<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Tests\Acces\AccesApiTestCase;

/**
 * Supervision temps réel & ouverture manuelle (US-L3-06, écran A-03, CA-7) : jauge/flux/incidents
 * s'actualisent ; une ouverture manuelle est tracée (agent, motif, horodatage).
 */
final class SupervisionTest extends AccesApiTestCase
{
    public function testCa7SupervisionExposeJaugesControleursEtIncidents(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Un refus (support inconnu) doit apparaître en incident.
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $this->idEquipement(), 'identifiantSupport' => 'INCONNU-0001'],
        ]);
        self::assertSame('refuse', $client->getResponse()->toArray()['resultat']);

        $client->request('GET', '/api/acces/supervision', $entete);
        self::assertResponseIsSuccessful();
        $vue = $client->getResponse()->toArray();

        self::assertNotEmpty($vue['jauges']);
        self::assertNotEmpty($vue['controleurs']);
        self::assertSame('en_ligne', $vue['controleurs'][0]['etat']);
        self::assertNotEmpty($vue['incidents']);
    }

    public function testCa7OuvertureManuelleTraceeAgentMotifHorodatage(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages/manuel', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $this->idEquipement(), 'motif' => 'Badge défectueux, franchissement forcé'],
        ]);
        self::assertResponseIsSuccessful();
        $passage = $client->getResponse()->toArray();

        self::assertSame('valide', $passage['resultat']);
        self::assertSame('ouverture_manuelle', $passage['codeMotif']);
        self::assertSame('Badge défectueux, franchissement forcé', $passage['motif']);
        self::assertNotEmpty($passage['agent']);
        self::assertNotEmpty($passage['horodatage']);
    }

    public function testCa7OuvertureManuelleSansMotifRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages/manuel', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $this->idEquipement(), 'motif' => ''],
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
