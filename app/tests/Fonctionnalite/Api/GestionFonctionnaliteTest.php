<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Api;

use App\DataFixtures\SocleFixtures;
use App\Tests\Fonctionnalite\FonctionnaliteApiTestCase;

/**
 * GET/PATCH /etablissements/{id}/fonctionnalites, POST /etablissements/{id}/appliquer-preset :
 * activer/désactiver/paramétrer une capacité, appliquer un preset, cloisonnement établissement.
 */
final class GestionFonctionnaliteTest extends FonctionnaliteApiTestCase
{
    public function testLetatExposeToutesLesCapacitesDuCatalogueAvecLePresetPiscineActifSurEtabA(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $reponse = $client->request('GET', '/api/etablissements/' . $idA . '/fonctionnalites', $entete);
        self::assertResponseIsSuccessful();

        $donnees = $reponse->toArray();
        $membres = $donnees['member'] ?? $donnees['hydra:member'];
        self::assertGreaterThanOrEqual(12, \count($membres));

        $etatParCode = [];
        foreach ($membres as $item) {
            $etatParCode[$item['capaciteCode']] = $item['active'];
        }

        // Preset « piscine » (FonctionnaliteFixtures) : contrôle d'accès + POSS actifs.
        self::assertTrue($etatParCode['controle_acces']);
        self::assertTrue($etatParCode['poss']);
        // Capacité jamais activée sur cet établissement : présente, inactive.
        self::assertArrayHasKey('boutique_en_ligne', $etatParCode);
        self::assertFalse($etatParCode['boutique_en_ligne']);
    }

    public function testActiverUneCapaciteAvecDesParametresPuisLaDesactiver(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $url = '/api/etablissements/' . $idA . '/fonctionnalites';
        $entetePatch = $this->entetePatch($entete);

        // Activation avec paramètres.
        $reponse = $client->request('PATCH', $url, $entetePatch + [
            'json' => ['capaciteCode' => 'boutique_en_ligne', 'active' => true, 'parametres' => ['delaiAnnulationHeures' => 24]],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        self::assertSame('boutique_en_ligne', $donnees['capaciteCode']);
        self::assertTrue($donnees['active']);
        self::assertSame(24, $donnees['parametres']['delaiAnnulationHeures']);

        // Confirmée dans l'état complet.
        $etat = $client->request('GET', $url, $entete)->toArray();
        $membres = $etat['member'] ?? $etat['hydra:member'];
        $actif = current(array_filter($membres, static fn (array $i): bool => $i['capaciteCode'] === 'boutique_en_ligne'));
        self::assertTrue($actif['active']);

        // Désactivation.
        $client->request('PATCH', $url, $entetePatch + [
            'json' => ['capaciteCode' => 'boutique_en_ligne', 'active' => false],
        ]);
        self::assertResponseIsSuccessful();

        $etatApres = $client->request('GET', $url, $entete)->toArray();
        $membresApres = $etatApres['member'] ?? $etatApres['hydra:member'];
        $desactive = current(array_filter($membresApres, static fn (array $i): bool => $i['capaciteCode'] === 'boutique_en_ligne'));
        self::assertFalse($desactive['active']);
    }

    public function testPatchAvecUnCapaciteCodeInconnuEstRejete(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $client->request('PATCH', '/api/etablissements/' . $idA . '/fonctionnalites', $this->entetePatch($entete) + [
            'json' => ['capaciteCode' => 'capacite_inexistante', 'active' => true],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPatchSansChampActiveEstRejete(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $client->request('PATCH', '/api/etablissements/' . $idA . '/fonctionnalites', $this->entetePatch($entete) + [
            'json' => ['capaciteCode' => 'casiers'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAppliquerUnPresetActiveLesCapacitesDuMetierSansDesactiverLesAutres(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        // Piscine A porte déjà le preset piscine ; on applique en plus le preset « padel » (additif).
        $reponse = $client->request('POST', '/api/etablissements/' . $idA . '/appliquer-preset', $entete + [
            'json' => ['metier' => 'padel'],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        self::assertSame('padel', $donnees['metier']);
        self::assertContains('boutique_en_ligne', $donnees['capacitesActivees']);
        self::assertContains('no_show', $donnees['capacitesActivees']);

        $etat = $client->request('GET', '/api/etablissements/' . $idA . '/fonctionnalites', $entete)->toArray();
        $membres = $etat['member'] ?? $etat['hydra:member'];
        $etatParCode = [];
        foreach ($membres as $item) {
            $etatParCode[$item['capaciteCode']] = $item['active'];
        }

        // Nouvelles capacités du preset padel actives...
        self::assertTrue($etatParCode['no_show']);
        self::assertTrue($etatParCode['boutique_en_ligne']);
        // ... ET les capacités du preset piscine appliqué précédemment restent actives (additif).
        self::assertTrue($etatParCode['controle_acces']);
        self::assertTrue($etatParCode['poss']);
    }

    public function testAppliquerUnPresetAvecUnMetierInconnuEstRejete(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $client->request('POST', '/api/etablissements/' . $idA . '/appliquer-preset', $entete + [
            'json' => ['metier' => 'plage'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testLeLecteurPeutLireMaisPasGererLesFonctionnalitesDeSonEtablissement(): void
    {
        [$client, $entete] = $this->lecteurSurA();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $client->request('GET', '/api/etablissements/' . $idA . '/fonctionnalites', $entete);
        self::assertResponseIsSuccessful();

        $client->request('PATCH', '/api/etablissements/' . $idA . '/fonctionnalites', $this->entetePatch($entete) + [
            'json' => ['capaciteCode' => 'casiers', 'active' => true],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCloisonnementLeLecteurNAyantAucuneAffectationSurBEstRefuseMemeAvecUnJetonValide(): void
    {
        [$client, $entete] = $this->lecteurSurA();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        // Le lecteur n'est affecté qu'à l'établissement A (SocleFixtures) : le chemin cible B doit être
        // refusé, quel que soit l'en-tête X-Etablissement transmis (RG-SOCLE-05).
        $client->request('GET', '/api/etablissements/' . $idB . '/fonctionnalites', $entete);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLaVerificationDeDroitPorteSurLetablissementDuCheminPasSurLenTeteActive(): void
    {
        // L'administrateur a une affectation sur A ET B (SocleFixtures) : en envoyant l'en-tête actif
        // « A » mais en ciblant le chemin « B », l'accès doit tout de même être autorisé — preuve que le
        // contrôle est bien lié au chemin, pas à l'en-tête (cf. GardeFonctionnaliteEtablissement).
        [$client, $entete] = $this->adminSurA();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $reponse = $client->request('GET', '/api/etablissements/' . $idB . '/fonctionnalites', $entete);
        self::assertResponseIsSuccessful();

        $donnees = $reponse->toArray();
        $membres = $donnees['member'] ?? $donnees['hydra:member'];
        $etatParCode = [];
        foreach ($membres as $item) {
            $etatParCode[$item['capaciteCode']] = $item['active'];
        }
        // Preset « sport » (FonctionnaliteFixtures) appliqué sur B.
        self::assertTrue($etatParCode['sepa']);
        self::assertTrue($etatParCode['acces_nocturne']);
    }
}
