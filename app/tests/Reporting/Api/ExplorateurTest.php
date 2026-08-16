<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Reporting\DataFixtures\L11Fixtures;
use App\Tests\Reporting\ReportingApiTestCase;

/**
 * M7-04 (CA-5, RG-M7-02) : l'Explorateur renvoie une valeur strictement cohérente avec celle du
 * dashboard pour le même indicateur/périmètre — garanti par construction (même `MesureLookupService`).
 */
final class ExplorateurTest extends ReportingApiTestCase
{
    public function testExplorateurRenvoieLaMemeValeurQueLeDashboardRegion(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authRegion();
        $idRegionA = $this->idRegion(L11Fixtures::REGION_A_NOM);

        $dashboard = $client->request('GET', '/reporting/dashboards/region/' . $idRegionA, $entete)->toArray();

        $explorateur = $client->request('GET', '/reporting/explorateur', $entete + [
            'query' => ['indicateur' => 'CA', 'niveau' => 'region', 'entiteId' => $idRegionA],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame($dashboard['indicateurs']['CA']['valeur'], $explorateur['valeur']);
        self::assertSame($dashboard['indicateurs']['CA']['statutCompletude'], $explorateur['statutCompletude']);
    }

    public function testExplorateurRenvoieLaMemeValeurQueLeDashboardEtablissement(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authSite();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);

        $dashboard = $client->request('GET', '/reporting/dashboards/etablissement/' . $idA1, $entete)->toArray();

        $explorateur = $client->request('GET', '/reporting/explorateur', $entete + [
            'query' => ['indicateur' => 'CA', 'niveau' => 'etablissement', 'entiteId' => $idA1],
        ])->toArray();

        self::assertSame($dashboard['caJour'], $explorateur['valeur']);
    }

    public function testFiltreParRegimeRecomposeUneComparabiliteStricte(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authRegion();
        $idRegionA = $this->idRegion(L11Fixtures::REGION_A_NOM);

        $regie = $client->request('GET', '/reporting/explorateur', $entete + [
            'query' => ['indicateur' => 'CA', 'niveau' => 'region', 'entiteId' => $idRegionA, 'regimeExploitant' => 'regie'],
        ])->toArray();

        self::assertSame('120.00', $regie['valeur'], 'Filtré régie seule : CA A1 uniquement.');
        self::assertSame(1, $regie['nombreSites']);
    }
}
