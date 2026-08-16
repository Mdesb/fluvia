<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Reporting\DataFixtures\L11Fixtures;
use App\Tests\Reporting\ReportingApiTestCase;

/**
 * `TableauDeBord` (§1.6 plan-reporting.md) : au moins un indicateur requis (validation native
 * `Assert\Count(min: 1)`).
 */
final class TableauDeBordTest extends ReportingApiTestCase
{
    public function testCreationSansIndicateurEchoue422(): void
    {
        [$client, $entete] = $this->authAdmin();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);

        $client->request('POST', '/api/tableau_de_bords', $entete + [
            'json' => ['nom' => 'TDB vide', 'niveau' => 'etablissement', 'etablissement' => '/api/etablissements/' . $idA1, 'indicateurs' => []],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreationAvecAuMoinsUnIndicateurReussit(): void
    {
        $tdb = $this->creerTableauDeBord('etablissement', '/api/etablissements/' . $this->idEtablissement(L11Fixtures::SITE_A1_NOM));

        self::assertNotSame('', $tdb);
    }

    public function testEcritureReserveeAReportingConfigurer(): void
    {
        [$client, $entete] = $this->authRegion();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);

        $client->request('POST', '/api/tableau_de_bords', $entete + [
            'json' => ['nom' => 'TDB', 'niveau' => 'etablissement', 'etablissement' => '/api/etablissements/' . $idA1, 'indicateurs' => ['/api/indicateurs/' . $this->idIndicateur('CA')]],
        ]);

        self::assertResponseStatusCodeSame(403, 'reporting.planifier ne suffit pas, seul reporting.configurer peut créer un TableauDeBord.');
    }
}
