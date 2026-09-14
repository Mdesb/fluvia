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

    /**
     * UN EXPORT ENVOYÉ RESTE TÉLÉCHARGEABLE.
     *
     * `ExecuterRapportsCommand` stocke le fichier, passe à `Genere`, expédie le courriel, puis
     * passe à `Envoye` — sans toucher au chemin de stockage. Le fichier est donc toujours là, et
     * le garde du provider excluait pourtant tout ce qui n'était pas `Genere` : le destinataire
     * qui avait reçu son rapport s'entendait répondre « la génération n'a pas abouti ».
     *
     * ⚠ ON NE PASSE PAS PAR LA COMMANDE. Elle envoie un courriel, et ce n'est pas le transport
     * qu'on teste ici — c'est le changement d'état. On reproduit donc exactement son geste : le
     * statut bascule, le chemin de stockage ne bouge pas.
     */
    public function testExportEnvoyeResteTelechargeable(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authSite();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);

        $reponse = $client->request('POST', '/api/reporting/exports', $entete + [
            'json' => ['format' => 'csv', 'niveau' => 'etablissement', 'entiteId' => $idA1, 'indicateurs' => ['CA']],
        ]);
        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent());
        $export = $reponse->toArray();
        self::assertSame('genere', $export['statut']);

        // Le geste de la commande, et lui seul.
        $em = static::getContainer()->get('doctrine')->getManager();
        $entite = $this->entite(\App\Reporting\Entity\Export::class, ['id' => $export['id']]);
        $cheminAvant = $entite->getCheminStockage();
        self::assertNotNull($cheminAvant, 'un export généré doit avoir un fichier');
        $entite->setStatut(\App\Reporting\Enum\StatutExport::Envoye);
        $em->flush();
        $em->clear();

        // ⚠ LE COEUR DU TEST : le fichier n'a pas bougé, donc il doit se servir.
        // ⚠ LA PORTE DE L'ECRAN, PAS CELLE DU CONTROLEUR.
        // Deux routes servent ce fichier : le controleur sur /reporting/..., qui acceptait
        // deja Envoye, et le provider sur /api/reporting/..., qui le refusait. C'est la
        // SECONDE que api.telechargerExportAnalyse appelle. Ce test, copie de son voisin,
        // visait d'abord la premiere : il passait du premier coup sans rien prouver.
        $telechargement = $client->request('GET', '/api/reporting/exports/' . $export['id'] . '/telecharger', $entete);
        self::assertResponseIsSuccessful(
            'un export ENVOYÉ garde son fichier : le refuser dit « la génération n\'a pas abouti » '
            . 'à quelqu\'un qui l\'a reçue',
        );
        self::assertStringContainsString('CA', $telechargement->getContent());

        $relu = $this->entite(\App\Reporting\Entity\Export::class, ['id' => $export['id']]);
        self::assertSame($cheminAvant, $relu->getCheminStockage(), 'le téléchargement ne déplace rien');
        self::assertSame(\App\Reporting\Enum\StatutExport::Envoye, $relu->getStatut(), 'ni ne change l\'état');
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
