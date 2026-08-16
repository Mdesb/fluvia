<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Reporting\DataFixtures\L11Fixtures;
use App\Tests\Reporting\ReportingApiTestCase;

/**
 * M7-03 (CA-4, §2.4/§3 plan-reporting.md) : dashboard groupe consolidé, drill-down région → site
 * sans changer d'axes (RG-M7-05, mêmes indicateurs/codes que M7-01/M7-02).
 */
final class DashboardGroupeTest extends ReportingApiTestCase
{
    public function testDirectionGeneraleVoitLeConsolideDeToutesLesRegions(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authGroupe();
        $idGroupe = $this->idGroupe();

        $donnees = $client->request('GET', '/reporting/dashboards/groupe/' . $idGroupe, $entete)->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('240.00', $donnees['indicateurs']['CA']['valeur']);
        self::assertCount(2, $donnees['regions']);
    }

    public function testDrillDownRegionPuisSiteSansChangerDAxes(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authGroupe();

        $regionPayload = $client->request('GET', '/reporting/dashboards/region/' . $this->idRegion(L11Fixtures::REGION_A_NOM), $entete)->toArray();
        self::assertSame('200.00', $regionPayload['indicateurs']['CA']['valeur']);

        $sitePayload = $client->request('GET', '/reporting/dashboards/etablissement/' . $this->idEtablissement(L11Fixtures::SITE_A1_NOM), $entete)->toArray();
        self::assertSame(L11Fixtures::CA_A1, $sitePayload['caJour']);
    }
}
