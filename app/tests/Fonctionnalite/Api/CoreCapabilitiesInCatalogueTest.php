<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Api;

use App\DataFixtures\SocleFixtures;
use App\Tests\Fonctionnalite\FonctionnaliteApiTestCase;

/**
 * Recentrage, dernière relecture de #317 — l'écran des capacités sait lesquelles sont du cœur.
 *
 * Le catalogue (`GET /fonctionnalites/catalogue`) dit `estDuCoeur` pour chaque capacité. Le test
 * confronte ce drapeau au refus réel, capacité par capacité : `estDuCoeur` vrai ⇔ l'éteindre rend 422.
 * Un écart montrerait un bouton « Mettre hors service » voué au refus, ou cacherait celui d'une
 * capacité qu'on peut couper.
 */
final class CoreCapabilitiesInCatalogueTest extends FonctionnaliteApiTestCase
{
    public function testTheCatalogueSaysExactlyWhichCapabilitiesCannotBeSwitchedOff(): void
    {
        [$client, $headers] = $this->adminSurA();
        $catalogue = $client->request('GET', '/api/fonctionnalites/catalogue', $headers)->toArray();
        $items = $catalogue['member'] ?? $catalogue['hydra:member'];
        self::assertGreaterThanOrEqual(30, \count($items), 'témoin : le catalogue est lu');

        $url = '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM) . '/fonctionnalites';
        $core = 0;
        foreach ($items as $item) {
            self::assertArrayHasKey('estDuCoeur', $item, $item['code']);
            $client->request('PATCH', $url, $this->entetePatch($headers) + [
                'json' => ['capaciteCode' => $item['code'], 'active' => false],
            ]);
            $refused = $client->getResponse()->getStatusCode() === 422;
            self::assertSame($item['estDuCoeur'], $refused, sprintf('%s : estDuCoeur=%s mais refus=%s', $item['code'], var_export($item['estDuCoeur'], true), var_export($refused, true)));
            $core += $item['estDuCoeur'] ? 1 : 0;
        }

        self::assertSame(12, $core, 'les 12 capacités du cœur (D133)');
    }
}
