<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Api;

use App\DataFixtures\SocleFixtures;
use App\Tests\Fonctionnalite\FonctionnaliteApiTestCase;

/**
 * Décision de Maxime du 08/10 : les modules qu'un établissement n'utilise pas sont masqués de son
 * menu, et s'activent un par un. Le menu se construit sur `capacitesActives` de `/me` : c'est donc là
 * que le masquage et l'activation se prouvent côté serveur.
 */
final class HiddenModulesTest extends FonctionnaliteApiTestCase
{
    public function testAPoolDoesNotCarryDealsUntilAnAdministratorEnablesThem(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        // Preset « piscine » appliqué sur A (FonctionnaliteFixtures).
        $capacites = $client->request('GET', '/me', $entete)->toArray()['capacitesActives'];
        self::assertContains('piscine', $capacites, 'le preset piscine active son propre écran');
        self::assertNotContains('affaires', $capacites, 'Affaires est hors de tout preset : masqué');

        $client->request('PATCH', '/api/etablissements/' . $idA . '/fonctionnalites', $this->entetePatch($entete) + [
            'json' => ['capaciteCode' => 'affaires', 'active' => true],
        ]);
        self::assertResponseIsSuccessful();

        $capacites = $client->request('GET', '/me', $entete)->toArray()['capacitesActives'];
        self::assertContains('affaires', $capacites, 'activé par un administrateur, il apparaît');
    }
}
