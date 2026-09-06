<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\DataFixtures\SocleFixtures;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * AUDIT DU 06/09, CONSTAT 4 — le jeton d'un client final n'ouvre que l'espace client.
 *
 * Vérifié en préproduction : `POST /boutique/comptes` (201) puis `/auth` (200) rendaient un JWT qui
 * franchissait toutes les portes « connecté, et rien de plus » du back-office — la liste des
 * établissements, l'agenda, les capacités. Le compte de démonstration `client.boutique@…` est un client
 * final (`AccountKind::Customer`) ; ce que son jeton atteint, et ce qu'il n'atteint pas, se lit ici.
 *
 * L'exploitant est le témoin : sans lui, un listener qui refuserait tout le monde passerait les refus.
 */
final class CustomerAccountScopeTest extends BoutiqueApiTestCase
{
    public function testUnClientFinalNAtteintPasLeBackOffice(): void
    {
        $client = static::createClient();
        $jeton = $this->jeton($client, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);

        $client->request('GET', '/api/etablissements', ['auth_bearer' => $jeton]);
        self::assertResponseStatusCodeSame(403, 'la liste des établissements est du back-office');

        $client->request('GET', '/me', ['auth_bearer' => $jeton]);
        self::assertResponseStatusCodeSame(403, 'le profil du back-office aussi : un client qui se trompe de porte doit le lire');
    }

    public function testUnClientFinalAtteintSonEspaceClient(): void
    {
        $client = static::createClient();
        $jeton = $this->jeton($client, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);

        $client->request('GET', '/api/boutique/comptes/me', ['auth_bearer' => $jeton]);

        self::assertResponseIsSuccessful();
    }

    /** Le témoin : le même chemin, avec un exploitant — sinon les refus ci-dessus ne prouveraient rien. */
    public function testUnExploitantAtteintToujoursLeBackOffice(): void
    {
        $client = static::createClient();
        $jeton = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        $client->request('GET', '/api/etablissements', ['auth_bearer' => $jeton]);

        self::assertResponseIsSuccessful();
    }
}
