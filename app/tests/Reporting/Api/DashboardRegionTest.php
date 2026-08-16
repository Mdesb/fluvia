<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Reporting\DataFixtures\L11Fixtures;
use App\Tests\Reporting\ReportingApiTestCase;

/**
 * M7-02 (CA-3, CA-9, §2.4/§2.7 plan-reporting.md) : dashboard région — sites côte à côte,
 * consolidation multi-régime avec indicateur de comparabilité, vues régime isolées non fusionnées.
 */
final class DashboardRegionTest extends ReportingApiTestCase
{
    public function testDirecteurRegionalVoitLesSitesCoteACoteEtLeCumulRegion(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authRegion();
        $idRegionA = $this->idRegion(L11Fixtures::REGION_A_NOM);

        $reponse = $client->request('GET', '/reporting/dashboards/region/' . $idRegionA, $entete);

        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        self::assertSame('200.00', $donnees['indicateurs']['CA']['valeur']);
        self::assertCount(2, $donnees['sites']);
        // Classement : A1 (120) avant A2 (80).
        self::assertSame(L11Fixtures::SITE_A1_NOM, $donnees['sites'][0]['nom']);
        self::assertSame(L11Fixtures::SITE_A2_NOM, $donnees['sites'][1]['nom']);
    }

    public function testDrillDownVersUnSiteDeLaRegion(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authRegion();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);

        $reponse = $client->request('GET', '/reporting/dashboards/etablissement/' . $idA1, $entete);

        self::assertResponseIsSuccessful();
        self::assertSame(L11Fixtures::CA_A1, $reponse->toArray()['caJour']);
    }

    public function testConsolidationMultiRegimeExposeLaComparabiliteEtLesVuesIsolees(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authRegion();
        $idRegionA = $this->idRegion(L11Fixtures::REGION_A_NOM);

        $donnees = $client->request('GET', '/reporting/dashboards/region/' . $idRegionA, $entete)->toArray();

        self::assertTrue($donnees['comparabiliteRegime']);
        self::assertTrue($donnees['indicateurs']['CA']['comparabiliteRegime']);
        self::assertNotEmpty($donnees['vuesRegimeIsolees'], 'Vues régime isolées disponibles (RAD/redevances DSP, état régie) sans être fusionnées.');
        foreach ($donnees['vuesRegimeIsolees'] as $vue) {
            self::assertArrayHasKey('regime', $vue);
            self::assertArrayNotHasKey('ca', $vue, 'La vue isolée ne doit jamais porter un CA fusionné dans l\'agrégat commun.');
        }
    }

    public function testMesurePartielleEstSignaleeSansBloquerLaVue(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authRegion();
        $idRegionA = $this->idRegion(L11Fixtures::REGION_A_NOM);

        $donnees = $client->request('GET', '/reporting/dashboards/region/' . $idRegionA, $entete)->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('partiel', $donnees['indicateurs']['CA']['statutCompletude']);
        self::assertNotEmpty($donnees['indicateurs']['CA']['sitesManquants']);
    }
}
