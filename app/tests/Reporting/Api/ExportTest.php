<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Reporting\DataFixtures\L11Fixtures;
use App\Tests\Reporting\ReportingApiTestCase;

/**
 * Export manuel (§2.8/§3 plan-reporting.md, ⚠ HYPOTHÈSE §3 spec) : CSV réellement généré et
 * téléchargeable ; PDF/XLSX échouent proprement (`Export.statut = echec`).
 */
final class ExportTest extends ReportingApiTestCase
{
    public function testExportCsvEstGenereEtTelechargeable(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authSite();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);

        $reponse = $client->request('POST', '/api/reporting/exports', $entete + [
            'json' => ['format' => 'csv', 'niveau' => 'etablissement', 'entiteId' => $idA1, 'indicateurs' => ['CA', 'FREQUENTATION_CUMULEE']],
        ]);

        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent());
        $export = $reponse->toArray();
        self::assertSame('genere', $export['statut']);

        $telechargement = $client->request('GET', '/reporting/exports/' . $export['id'] . '/telecharger', $entete);
        self::assertResponseIsSuccessful();
        $contenu = $telechargement->getContent();
        self::assertStringContainsString('CA', $contenu);
        self::assertStringContainsString('FREQUENTATION_CUMULEE', $contenu);
        self::assertStringContainsString(L11Fixtures::CA_A1, $contenu);
    }

    public function testExportPdfEchoueProprementSansPlanterSilencieusement(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authSite();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);

        $reponse = $client->request('POST', '/api/reporting/exports', $entete + [
            'json' => ['format' => 'pdf', 'niveau' => 'etablissement', 'entiteId' => $idA1, 'indicateurs' => ['CA']],
        ]);

        self::assertResponseStatusCodeSame(201);
        $export = $reponse->toArray();
        self::assertSame('echec', $export['statut']);
        self::assertNotEmpty($export['messageErreur']);
    }

    public function testExportHorsPerimetreEstRefuse(): void
    {
        [$client, $entete] = $this->authSite();
        $idB1 = $this->idEtablissement(L11Fixtures::SITE_B1_NOM);

        $client->request('POST', '/api/reporting/exports', $entete + [
            'json' => ['format' => 'csv', 'niveau' => 'etablissement', 'entiteId' => $idB1],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTelechargementRefusePourUnAutreUtilisateurSansReportingConfigurer(): void
    {
        $this->agreger();
        [$clientSite, $enteteSite] = $this->authSite();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);
        $export = $clientSite->request('POST', '/api/reporting/exports', $enteteSite + [
            'json' => ['format' => 'csv', 'niveau' => 'etablissement', 'entiteId' => $idA1],
        ])->toArray();

        // Le directeur régional a bien A1 dans son périmètre, mais n'est PAS demandePar => refusé
        // sauf reporting.configurer (traçabilité admin, §3 plan-reporting.md).
        [$clientRegion, $enteteRegion] = $this->authRegion();
        $clientRegion->request('GET', '/reporting/exports/' . $export['id'] . '/telecharger', $enteteRegion);
        self::assertResponseStatusCodeSame(403);

        [$clientAdmin, $enteteAdmin] = $this->authAdmin();
        $clientAdmin->request('GET', '/reporting/exports/' . $export['id'] . '/telecharger', $enteteAdmin);
        self::assertResponseIsSuccessful();
    }
}
