<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Api;

use App\DataFixtures\SocleFixtures;
use App\Tests\Fonctionnalite\FonctionnaliteApiTestCase;

/**
 * `GET /me` expose désormais `capacitesActives` (établissement actif) sans casser le contrat existant
 * (US-L0-05) — l'UI peut n'afficher que le pertinent (règle d'or §2 constitution.md).
 */
final class MeCapacitesActivesTest extends FonctionnaliteApiTestCase
{
    public function testMeExposeLesCapacitesActivesDeLetablissementActif(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('GET', '/me', $entete);
        self::assertResponseIsSuccessful();

        $donnees = $reponse->toArray();
        // Contrat existant préservé.
        self::assertArrayHasKey('id', $donnees);
        self::assertArrayHasKey('droits', $donnees);
        self::assertArrayHasKey('etablissementActif', $donnees);

        // Nouveau champ additif : preset piscine appliqué sur A (FonctionnaliteFixtures).
        self::assertArrayHasKey('capacitesActives', $donnees);
        self::assertContains('controle_acces', $donnees['capacitesActives']);
        self::assertContains('poss', $donnees['capacitesActives']);
        self::assertNotContains('acces_nocturne', $donnees['capacitesActives']);
    }

    public function testMeRenvoieUneListeVideSansEtablissementActif(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        $reponse = $client->request('GET', '/me', ['auth_bearer' => $token]);
        self::assertResponseIsSuccessful();

        $donnees = $reponse->toArray();
        self::assertNull($donnees['etablissementActif']);
        self::assertSame([], $donnees['capacitesActives']);
    }
}
