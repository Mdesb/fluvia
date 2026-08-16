<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Reporting\DataFixtures\L11Fixtures;
use App\Tests\Reporting\ReportingApiTestCase;

/**
 * Cloisonnement par niveau (CA-1, RG-M7-01, RG-M8-04, §2.2 plan-reporting.md) : un utilisateur ne
 * voit que son périmètre ; cas limite « affectations non contiguës » (spec §7).
 */
final class CloisonnementTest extends ReportingApiTestCase
{
    public function testDirecteurRegionalVoitSaRegionMaisPasUneAutreRegionNiUnSiteHorsPerimetre(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authRegion();

        $client->request('GET', '/reporting/dashboards/region/' . $this->idRegion(L11Fixtures::REGION_A_NOM), $entete);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/reporting/dashboards/region/' . $this->idRegion(L11Fixtures::REGION_B_NOM), $entete);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/reporting/dashboards/etablissement/' . $this->idEtablissement(L11Fixtures::SITE_B1_NOM), $entete);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDirecteurDeSiteNAccedePasAuDashboardRegion(): void
    {
        [$client, $entete] = $this->authSite();

        $client->request('GET', '/reporting/dashboards/region/' . $this->idRegion(L11Fixtures::REGION_A_NOM), $entete);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUtilisateurAAffectationsNonContiguesNaAucunDashboardRegionOuGroupeExpose(): void
    {
        [$client, $entete] = $this->authNonContigu();

        // A1 et B1 seuls (aucune région/groupe entièrement couverte) : dashboards région/groupe refusés.
        $client->request('GET', '/reporting/dashboards/region/' . $this->idRegion(L11Fixtures::REGION_A_NOM), $entete);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/reporting/dashboards/groupe/' . $this->idGroupe(), $entete);
        self::assertResponseStatusCodeSame(403);

        // Mais l'Explorateur/M7-01 restent accessibles, bornés à ses établissements affectés.
        $client->request('GET', '/reporting/dashboards/etablissement/' . $this->idEtablissement(L11Fixtures::SITE_A1_NOM), $entete);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/reporting/dashboards/etablissement/' . $this->idEtablissement(L11Fixtures::SITE_A2_NOM), $entete);
        self::assertResponseStatusCodeSame(403, 'A2 non affecté à cet utilisateur.');
    }

    public function testMesureApiEstFiltreeParPerimetre(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authSite();

        $reponse = $client->request('GET', '/api/mesures', $entete + ['headers' => ['Accept' => 'application/ld+json']]);
        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        $idB1 = $this->idEtablissement(L11Fixtures::SITE_B1_NOM);
        foreach ($donnees['member'] ?? $donnees['hydra:member'] ?? [] as $mesure) {
            if (isset($mesure['etablissement'])) {
                self::assertStringNotContainsString($idB1, (string) $mesure['etablissement'], 'Le directeur de site A1 ne doit jamais voir une Mesure de B1.');
            }
        }
    }
}
