<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Api;

use App\DataFixtures\SocleFixtures;
use App\Tests\Fonctionnalite\FonctionnaliteApiTestCase;

/**
 * Recentrage, CA-4 — un module du cœur ne s'éteint pas par erreur.
 *
 * Piscine et Sport sont du cœur (spec du recentrage, précision de Maxime du 20/10 pour Sport) : ils
 * ne sont pas gardés, et l'écran des fonctionnalités ne peut pas les éteindre. Le refus est un 422
 * qui dit pourquoi, pas un 200 sans effet.
 *
 * Le témoin : un module masqué (Padel) s'éteint, lui, normalement — sans quoi le refus pourrait
 * venir de n'importe quel défaut de l'écriture.
 */
final class CoreModuleCannotBeSwitchedOffTest extends FonctionnaliteApiTestCase
{
    public function testSwitchingOffACoreModuleIsRefused(): void
    {
        [$client, $headers] = $this->adminSurA();
        $url = '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM) . '/fonctionnalites';

        foreach (['sport', 'piscine'] as $core) {
            $response = $client->request('PATCH', $url, $this->entetePatch($headers) + [
                'json' => ['capaciteCode' => $core, 'active' => false],
            ]);
            self::assertResponseStatusCodeSame(422, $core);
            self::assertStringContainsString('cœur', (string) json_encode($response->toArray(false), \JSON_UNESCAPED_UNICODE), $core);
        }
    }

    public function testSwitchingOnACoreModuleStillWorks(): void
    {
        [$client, $headers] = $this->adminSurA();
        $url = '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM) . '/fonctionnalites';

        $client->request('PATCH', $url, $this->entetePatch($headers) + [
            'json' => ['capaciteCode' => 'sport', 'active' => true],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testAMaskedModuleCanBeSwitchedOff(): void
    {
        [$client, $headers] = $this->adminSurA();
        $url = '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM) . '/fonctionnalites';

        $client->request('PATCH', $url, $this->entetePatch($headers) + [
            'json' => ['capaciteCode' => 'padel', 'active' => false],
        ]);
        self::assertResponseIsSuccessful();
    }
}
