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
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);
        $membres = $donnees['member'] ?? $donnees['hydra:member'] ?? [];

        // ⚠ DEUX TEMOINS, PARCE QUE LA BOUCLE CI-DESSOUS PASSE SUR UNE COLLECTION VIDE.
        //
        // Sans eux, ce test resterait vert si le cloisonnement cachait TOUT — filtre trop large,
        // agregation sans resultat, groupe de serialisation renomme. Il resterait vert aussi si la
        // cle `etablissement` disparaissait, puisque le `isset` la sauterait. Un cloisonnement qui
        // cache tout n'est pas un cloisonnement, c'est une panne.
        self::assertNotEmpty($membres, 'temoin : la collection doit rendre des mesures, sinon la boucle ne mesure rien');

        $siennes = array_filter(
            $membres,
            static fn (array $m): bool => isset($m['etablissement']) && str_contains((string) $m['etablissement'], $idA1),
        );
        self::assertNotEmpty(
            $siennes,
            'temoin : au moins une mesure de MON etablissement doit etre rendue, sous la cle '
            . '`etablissement` — sinon l\'assertion suivante porte sur un champ absent',
        );

        foreach ($membres as $mesure) {
            if (isset($mesure['etablissement'])) {
                self::assertStringNotContainsString($idB1, (string) $mesure['etablissement'], 'Le directeur de site A1 ne doit jamais voir une Mesure de B1.');
            }
        }
    }
}
