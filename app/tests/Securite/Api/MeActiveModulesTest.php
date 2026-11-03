<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\DataFixtures\SocleFixtures;
use App\Platform\Module\ModuleGuard;
use App\Platform\Module\ModuleRegistry;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Platform\ModuleSwitch;
use App\Tests\SocleApiTestCase;

/**
 * Recentrage, PR 2 (a) — `/me` dit quels modules sont actifs pour l'établissement courant.
 *
 * Le menu s'en sert pour cacher les entrées des modules éteints. La liste doit dire EXACTEMENT ce
 * que la garde serveur laisse passer : un écart enverrait un utilisateur vers un 404 (module
 * affiché mais éteint) ou lui cacherait un module servi. Le test le vérifie module par module, en
 * confrontant `/me` à une vraie requête sur une collection du module.
 */
final class MeActiveModulesTest extends SocleApiTestCase
{
    use ModuleSwitch;

    public function testMeListsCoreModulesAndOnlyTheMaskedModulesSwitchedOn(): void
    {
        $this->switchModule('padel', false, SocleFixtures::ETAB_A_NOM);
        $this->switchModule('padel', true, SocleFixtures::ETAB_B_NOM);

        $onA = $this->activeModules(SocleFixtures::ETAB_A_NOM);
        $onB = $this->activeModules(SocleFixtures::ETAB_B_NOM);

        foreach (ModuleGuard::CORE_MODULES as $core) {
            self::assertContains($core, $onA, sprintf('le cœur (« %s ») est toujours actif', $core));
        }
        self::assertNotContains('padel', $onA);
        self::assertContains('padel', $onB, 'témoin : allumé sur B');
        self::assertNotContains('musee', $onB, 'jamais allumé : absent');
    }

    /** La liste de `/me` et la garde serveur disent la même chose, pour chaque module du registre. */
    public function testMeAgreesWithTheServerGuardForEveryModule(): void
    {
        $this->switchModule('padel', true, SocleFixtures::ETAB_A_NOM);
        $this->switchModule('finance', true, SocleFixtures::ETAB_A_NOM);
        $active = $this->activeModules(SocleFixtures::ETAB_A_NOM);

        $client = static::createClient();
        $client->disableReboot();
        $headers = $this->headers($client, SocleFixtures::ETAB_A_NOM);
        $probes = ['padel' => '/api/padel_terrains', 'finance' => '/api/bank_accounts', 'musee' => '/api/musee_salles', 'social' => '/api/social_posts'];

        foreach ($probes as $module => $path) {
            $client->request('GET', $path, $headers);
            $served = $client->getResponse()->getStatusCode() !== 404;
            self::assertSame($served, \in_array($module, $active, true), sprintf('%s : /me et la garde divergent', $module));
        }

        /** @var ModuleRegistry $registry */
        $registry = static::getContainer()->get(ModuleRegistry::class);
        self::assertSame([], array_values(array_diff($active, array_keys($registry->all()))), 'un module inconnu du registre');
    }

    /** @return list<string> */
    private function activeModules(string $establishment): array
    {
        $client = static::createClient();
        $response = $client->request('GET', '/me', $this->headers($client, $establishment));
        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertArrayHasKey('modulesActifs', $body);

        return $body['modulesActifs'];
    }

    /** @return array<string, mixed> */
    private function headers(\ApiPlatform\Symfony\Bundle\Test\Client $client, string $establishment): array
    {
        return [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement($establishment)],
        ];
    }
}
