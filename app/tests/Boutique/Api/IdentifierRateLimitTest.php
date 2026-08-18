<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Security\TentativeIdentificationLimiter;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Revue de sécurité — faille majeure (#5) : anti-bruteforce sur `POST /boutique/paniers/{id}/identifier`
 * (mode `compte`, mot de passe validé hors firewall). Repli applicatif (`TentativeIdentificationLimiter`,
 * composant `symfony/rate-limiter` non installé) — au-delà du seuil, 429.
 *
 * ⚠ Le compteur repose sur le pool `cache.app` (fichiers sur disque, `var/cache/test/pools`), qui
 * **survit** au rechargement du schéma/fixtures entre tests (contrairement à la base isolée par
 * `TEST_TOKEN`) — ce test réinitialise donc explicitement son propre compteur avant et après, pour ne
 * jamais polluer/être pollué par d'autres tests réutilisant le même e-mail de démonstration
 * (`BoutiqueFixtures::CLIENT_EMAIL`).
 */
final class IdentifierRateLimitTest extends BoutiqueApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter()->reinitialiser(BoutiqueFixtures::CLIENT_EMAIL);
    }

    protected function tearDown(): void
    {
        $this->limiter()->reinitialiser(BoutiqueFixtures::CLIENT_EMAIL);
        parent::tearDown();
    }

    public function testLaNiemeTentativeEchoueeDeclencheUn429(): void
    {
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        // 5 tentatives échouées (mauvais mot de passe) sur le même compte : chacune répond 401.
        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
                'headers' => $entete,
                'json' => ['mode' => 'compte', 'email' => BoutiqueFixtures::CLIENT_EMAIL, 'motDePasse' => 'mauvais-mdp'],
            ]);
            self::assertResponseStatusCodeSame(401, sprintf('Tentative %d : identifiants invalides -> 401.', $i + 1));
        }

        // 6ᵉ tentative (même avec le bon mot de passe cette fois) : quota atteint -> 429.
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'compte', 'email' => BoutiqueFixtures::CLIENT_EMAIL, 'motDePasse' => BoutiqueFixtures::CLIENT_MDP],
        ]);
        self::assertResponseStatusCodeSame(429, 'Anti-bruteforce : quota de tentatives d\'identification atteint.');
    }

    private function limiter(): TentativeIdentificationLimiter
    {
        static::createClient();

        return static::getContainer()->get(TentativeIdentificationLimiter::class);
    }
}
