<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Reporting\DataFixtures\L11Fixtures;
use App\Tests\Reporting\ReportingApiTestCase;

/**
 * `RapportPlanifie` (RG-M7-06/07, §2.3 plan-reporting.md) : cloisonnement par destinataire à la
 * création (chaque destinataire ⊆ périmètre `reporting.planifier` du créateur, 422 sinon).
 *
 * NB — `creerTableauDeBord()` authentifie un admin en interne (`static::createClient()`), ce qui
 * réinitialise le client « courant » suivi par les assertions statiques
 * (`assertResponseStatusCodeSame`/`assertResponseIsSuccessful`, cf. `BrowserKitAssertionsTrait`) :
 * il est donc TOUJOURS appelé AVANT `authXxx()` dans ce fichier, pour que le client de l'acteur testé
 * reste le client « courant » au moment des assertions.
 */
final class RapportPlanifieTest extends ReportingApiTestCase
{
    public function testCreationAvecDestinatairesDansLePerimetreDuCreateur(): void
    {
        $tdb = $this->creerTableauDeBord('region', '/api/regions/' . $this->idRegion(L11Fixtures::REGION_A_NOM));
        [$client, $entete] = $this->authRegion();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);
        $idA2 = $this->idEtablissement(L11Fixtures::SITE_A2_NOM);

        $reponse = $client->request('POST', '/api/rapport_planifies', $entete + [
            'json' => [
                'nom' => 'Rapport hebdo région A',
                'tableauDeBord' => $tdb,
                'format' => 'csv',
                'periodicite' => 'hebdomadaire',
                'heureEnvoi' => '08:00',
                'destinataires' => [
                    ['email' => 'site-a1@itcotation.com', 'niveau' => 'etablissement', 'etablissement' => '/api/etablissements/' . $idA1],
                    ['email' => 'site-a2@itcotation.com', 'niveau' => 'etablissement', 'etablissement' => '/api/etablissements/' . $idA2],
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $donnees = $reponse->toArray();
        self::assertCount(2, $donnees['destinataires']);
        self::assertSame('actif', $donnees['etat']);
    }

    public function testCreationAvecDestinataireHorsPerimetreEchoue422(): void
    {
        $tdb = $this->creerTableauDeBord('region', '/api/regions/' . $this->idRegion(L11Fixtures::REGION_A_NOM));
        [$client, $entete] = $this->authRegion();
        $idB1 = $this->idEtablissement(L11Fixtures::SITE_B1_NOM);

        $client->request('POST', '/api/rapport_planifies', $entete + [
            'json' => [
                'nom' => 'Rapport invalide',
                'tableauDeBord' => $tdb,
                'format' => 'csv',
                'periodicite' => 'quotidienne',
                'heureEnvoi' => '08:00',
                'destinataires' => [
                    ['email' => 'hors-perimetre@itcotation.com', 'niveau' => 'etablissement', 'etablissement' => '/api/etablissements/' . $idB1],
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdministrateurPeutComposerUnRapportMultiNiveauSonPropreProfilPermetLesTrois(): void
    {
        $tdb = $this->creerTableauDeBord('groupe', '/api/groupes/' . $this->idGroupe());
        [$client, $entete] = $this->authAdmin();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);
        $idRegionA = $this->idRegion(L11Fixtures::REGION_A_NOM);

        $reponse = $client->request('POST', '/api/rapport_planifies', $entete + [
            'json' => [
                'nom' => 'Rapport multi-niveaux',
                'tableauDeBord' => $tdb,
                'format' => 'csv',
                'periodicite' => 'mensuelle',
                'heureEnvoi' => '09:00',
                'destinataires' => [
                    ['email' => 'site@itcotation.com', 'niveau' => 'etablissement', 'etablissement' => '/api/etablissements/' . $idA1],
                    ['email' => 'region@itcotation.com', 'niveau' => 'region', 'region' => '/api/regions/' . $idRegionA],
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(201, (string) $reponse->getContent(false));
    }

    public function testSuspensionEmpecheLaProchaineGenerationCote(): void
    {
        $tdb = $this->creerTableauDeBord('etablissement', '/api/etablissements/' . $this->idEtablissement(L11Fixtures::SITE_A1_NOM));
        [$client, $entete] = $this->authRegion();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);

        $reponse = $client->request('POST', '/api/rapport_planifies', $entete + [
            'json' => [
                'nom' => 'Rapport à suspendre',
                'tableauDeBord' => $tdb,
                'format' => 'csv',
                'periodicite' => 'quotidienne',
                'heureEnvoi' => '08:00',
                'destinataires' => [['email' => 'a1@itcotation.com', 'niveau' => 'etablissement', 'etablissement' => '/api/etablissements/' . $idA1]],
            ],
        ])->toArray();

        $client->request('PATCH', '/api/rapport_planifies/' . $reponse['id'], $entete + [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['etat' => 'suspendu'],
        ]);
        self::assertResponseIsSuccessful();

        $verif = $client->request('GET', '/api/rapport_planifies/' . $reponse['id'], $entete)->toArray();
        self::assertSame('suspendu', $verif['etat']);
    }
}
