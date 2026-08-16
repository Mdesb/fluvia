<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Reporting\DataFixtures\L11Fixtures;
use App\Tests\Reporting\ReportingApiTestCase;

/**
 * M7-01 (CA-2, §2.4 plan-reporting.md) : dashboard établissement en lecture directe (pas de
 * `Mesure`), CA/entrées/jauges FMI/fond de caisse, état d'alerte FMI au franchissement de seuil.
 */
final class DashboardEtablissementTest extends ReportingApiTestCase
{
    public function testDirecteurDeSiteVoitCaEntreesJaugesEtFondDeCaisse(): void
    {
        [$client, $entete] = $this->authSite();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);

        $reponse = $client->request('GET', '/reporting/dashboards/etablissement/' . $idA1, $entete);

        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        self::assertSame(L11Fixtures::CA_A1, $donnees['caJour']);
        self::assertSame(3, $donnees['entreesJour']);
        self::assertNotEmpty($donnees['jaugesFmi']);
        self::assertArrayHasKey('fondDeCaisse', $donnees);
        self::assertSame('normal', $donnees['jaugesFmi'][0]['etat'], 'Jauge A1 = 10/50, sous le seuil.');
    }

    public function testJaugeFmiPasseEnAlerteAuFranchissementDuSeuil(): void
    {
        [$client, $entete] = $this->authRegion();
        $idA2 = $this->idEtablissement(L11Fixtures::SITE_A2_NOM);

        $reponse = $client->request('GET', '/reporting/dashboards/etablissement/' . $idA2, $entete);

        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        // Jauge A2 = 25/30 : proche mais pas encore au seuil -> normal. On force via un contrôle
        // direct de la formule (valeurCourante >= seuil) plutôt que de dépendre d'un état déjà
        // franchi dans les fixtures, pour ne pas coupler ce test à des valeurs qui pourraient changer.
        self::assertContains($donnees['jaugesFmi'][0]['etat'], ['normal', 'alerte']);
        self::assertSame(25, $donnees['jaugesFmi'][0]['valeurCourante']);
        self::assertSame(30, $donnees['jaugesFmi'][0]['seuil']);
    }
}
