<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Tests\Acces\AccesApiTestCase;

/**
 * Comptage non nominatif (US-L3-04, RG-ACC-03, CA-5) : la fréquentation et la jauge FMI s'incrémentent
 * sans décompte de crédit ; le motif est requis et le passage apparaît distinctement (résultat « compte »).
 */
final class NonNominatifTest extends AccesApiTestCase
{
    public function testCa5ComptageNonNominatifIncrementeJaugeSansCredit(): void
    {
        [$client, $entete] = $this->adminSurA();

        $avant = $this->jauge($client, $entete);
        self::assertSame(0, $avant['valeurCourante']);

        $client->request('POST', '/api/acces/passages/non-nominatif', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'motif' => 'bebe',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $passage = $client->getResponse()->toArray();
        self::assertSame('compte', $passage['resultat']);
        self::assertNull($passage['support']);
        self::assertNull($passage['droit']);
        self::assertSame('non_nominatif', $passage['codeMotif']);
        self::assertSame('bebe', $passage['motif']);

        $apres = $this->jauge($client, $entete);
        self::assertSame(1, $apres['valeurCourante']);
        self::assertSame(1, $apres['cumulJour']);
    }

    public function testCa5MotifRequisSinonRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages/non-nominatif', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $this->idEquipement(), 'motif' => ''],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** @return array<string, mixed> */
    private function jauge(object $client, array $entete): array
    {
        $client->request('GET', '/api/jauge_fmis', $entete);
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        self::assertNotEmpty($membres);

        return $membres[0];
    }
}
