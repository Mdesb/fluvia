<?php

declare(strict_types=1);

namespace App\Tests\Caution\Api;

use App\Tests\Caution\CautionApiTestCase;

/** Grille de retenue générique (`caution.parametrer`) — CRUD paramétrage. */
final class GrilleRetenueApiTest extends CautionApiTestCase
{
    public function testCreationEtLectureDuneGrille(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();

        $client->request('POST', '/api/caution_grille_retenues', $entete + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $idA,
                'typeCible' => 'demo.cible',
                'motif' => 'casse',
                'mode' => 'forfait',
                'montantCentimes' => 1500,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $grille = $client->getResponse()->toArray();
        self::assertSame(1500, $grille['montantCentimes']);
        self::assertTrue($grille['actif']);

        $client->request('GET', '/api/caution_grille_retenues/' . $grille['id'], $entete);
        self::assertResponseIsSuccessful();
    }

    public function testEcritureRefuseeSansPermissionParametrer(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();

        // Le lecteur (*.lire) n'a pas caution.parametrer.
        $lecteur = static::createClient();
        $token = $this->jeton($lecteur, \App\DataFixtures\SocleFixtures::LECTEUR_EMAIL, \App\DataFixtures\SocleFixtures::LECTEUR_MDP);
        $lecteur->request('POST', '/api/caution_grille_retenues', [
            'auth_bearer' => $token,
            'json' => ['etablissement' => '/api/etablissements/' . $idA, 'typeCible' => 'demo.cible', 'motif' => 'casse', 'montantCentimes' => 100],
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
