<?php

declare(strict_types=1);

namespace App\Tests\Platform\Integration;

use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\DataFixtures\SocleFixtures;
use App\Platform\Module\ModuleGuard;
use App\Platform\Module\ModuleGuardListener;
use App\Platform\Module\ModuleRegistry;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Platform\ModuleSwitch;
use App\Tests\SocleApiTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Recentrage, CA-6 — toute opération d'un module à capacité est couverte, sans rien déclarer.
 *
 * Le test ÉNUMÈRE : les modules du registre, puis les routes du routeur qui leur appartiennent. Il
 * ne lit aucune liste de ressources tenue à la main — une ressource ajoutée demain dans un module
 * masqué entre dans la mesure sans que personne l'y mette.
 *
 * ⚠ L'APPARTENANCE EST RECALCULÉE ICI, PAS EMPRUNTÉE À LA GARDE. Le test déduit le module d'une
 * classe par le namespace de son manifeste, avec son propre code : s'il appelait la fonction de
 * la garde, une erreur de classement serait partagée par l'instrument et par ce qu'il mesure.
 *
 * Trois mesures :
 *  1. par HTTP, chaque collection d'un module masqué rend 404 éteint, et autre chose qu'un 404
 *     allumé (le témoin) — c'est la garde de bout en bout ;
 *  2. pour TOUTES les opérations (éléments, écritures, sous-ressources), la garde refuse quand le
 *     module est éteint et laisse passer quand il est allumé. Par HTTP, un 404 de garde et un 404
 *     d'identifiant inconnu se confondent : on appelle donc la garde elle-même, route par route ;
 *  3. aucune opération d'un module masqué n'est publique : la garde ne s'applique qu'avec un
 *     établissement, et une route publique en arriverait sans. Le jour où il en naît une, ce test
 *     tombe et oblige à décider d'où vient son établissement.
 */
final class ModuleGuardCoverageTest extends SocleApiTestCase
{
    use ModuleSwitch;

    /** Spec du recentrage + précision de Maxime du 20/10 (Sport) : modules du cœur qui ont une capacité. */
    private const CORE_WITH_CAPABILITY = ['piscine', 'sport'];

    public function testTheGuardAgreesWithTheSpecOnWhatIsCore(): void
    {
        foreach ($this->registry()->all() as $id => $manifest) {
            if ($manifest->capability() === null) {
                self::assertContains($id, ModuleGuard::CORE_MODULES, sprintf(
                    '« %s » n\'a pas de capacité : il ne peut pas être éteint, donc il doit être du cœur.', $id,
                ));
            }
        }

        foreach (self::CORE_WITH_CAPABILITY as $id) {
            self::assertContains($id, ModuleGuard::CORE_MODULES);
        }

        foreach (['smart_flow', 'revenue_recovery', 'import', 'ocr'] as $id) {
            self::assertNotNull($this->registry()->get($id)->capability(), sprintf('« %s » doit pouvoir être éteint.', $id));
            self::assertNotContains($id, ModuleGuard::CORE_MODULES);
        }
    }

    /**
     * PR 2 (c) : Caution, Project, Marketing et Personnel étaient masqués par la spec mais sans
     * manifeste — donc sans appartenance, donc jamais gardés. Ils ont désormais chacun le leur, avec une
     * capacité : les tests ci-dessous, qui énumèrent le registre, les couvrent sans les nommer.
     */
    public function testTheMaskedModulesThatHadNoManifestNowHaveOne(): void
    {
        foreach (['caution', 'project', 'marketing', 'personnel'] as $id) {
            self::assertTrue($this->registry()->has($id), sprintf('« %s » sans manifeste.', $id));
            self::assertNotNull($this->registry()->get($id)->capability(), $id);
            self::assertNotContains($id, ModuleGuard::CORE_MODULES, $id);
        }
    }

    public function testEveryCollectionOfAMaskedModuleIs404WhenSwitchedOffAndServedWhenOn(): void
    {
        $collections = [];
        foreach ($this->maskedRoutes() as [$module, $name, $route, $operation]) {
            if ($route->getMethods() === ['GET'] && $operation instanceof \ApiPlatform\Metadata\GetCollection
                && !str_contains(str_replace('.{_format}', '', $route->getPath()), '{')) {
                $collections[$module][] = str_replace('.{_format}', '', $route->getPath());
            }
        }

        self::assertGreaterThanOrEqual(7, \count($collections), 'témoin de l\'instrument : trop peu de modules mesurés');

        foreach (array_keys($collections) as $module) {
            $this->switchModule($this->registry()->get($module)->capability(), true, SocleFixtures::ETAB_A_NOM);
        }

        $client = static::createClient();
        $client->disableReboot();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $headers = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]];

        $wrong = [];
        foreach ($collections as $module => $paths) {
            foreach ($paths as $path) {
                $client->request('GET', $path, $headers);
                if ($client->getResponse()->getStatusCode() === 404) {
                    $wrong[] = sprintf('%s %s : 404 alors que le module est ALLUMÉ', $module, $path);
                }
            }
        }

        foreach (array_keys($collections) as $module) {
            $this->switchModule($this->registry()->get($module)->capability(), false, SocleFixtures::ETAB_A_NOM);
        }

        foreach ($collections as $module => $paths) {
            foreach ($paths as $path) {
                $client->request('GET', $path, $headers);
                $status = $client->getResponse()->getStatusCode();
                if ($status !== 404) {
                    $wrong[] = sprintf('%s %s : %d alors que le module est ÉTEINT', $module, $path, $status);
                }
            }
        }

        self::assertSame([], $wrong);
    }

    public function testEveryOperationOfAMaskedModuleIsRefusedWhenOffAndLetThroughWhenOn(): void
    {
        $routes = $this->maskedRoutes();
        self::assertGreaterThanOrEqual(200, \count($routes), 'témoin de l\'instrument : trop peu d\'opérations lues');

        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $uncovered = [];

        foreach ([false, true] as $on) {
            foreach ($this->registry()->all() as $id => $manifest) {
                if ($manifest->capability() !== null && !\in_array($id, self::CORE_WITH_CAPABILITY, true)) {
                    $this->switchModule($manifest->capability(), $on, SocleFixtures::ETAB_A_NOM);
                }
            }

            foreach ($routes as [$module, $name, $route]) {
                $refused = $this->guardRefuses($name, $route->getDefaults(), $idA);
                if ($refused !== !$on) {
                    $uncovered[] = sprintf('%s %s %s : %s alors que le module est %s', $module, implode('|', $route->getMethods()), $route->getPath(), $refused ? 'refusée' : 'servie', $on ? 'allumé' : 'éteint');
                }
            }
        }

        self::assertSame([], $uncovered);
    }

    /** Témoin négatif : la garde épargne le cœur, même quand tous les modules masqués sont éteints. */
    public function testTheGuardSparesCoreOperations(): void
    {
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $router = static::getContainer()->get(RouterInterface::class);
        $spared = 0;

        foreach (['_api_/reservations{._format}_get_collection', '_api_/reservations/{id}{._format}_get'] as $name) {
            $route = $router->getRouteCollection()->get($name);
            self::assertNotNull($route, $name);
            self::assertFalse($this->guardRefuses($name, $route->getDefaults(), $idA), $name);
            ++$spared;
        }

        self::assertSame(2, $spared);
    }

    public function testNoOperationOfAMaskedModuleIsPublic(): void
    {
        $public = [];
        foreach ($this->maskedRoutes() as [$module, $name, $route, $operation]) {
            if ($operation === null || $operation instanceof NotExposed) {
                continue;
            }
            $security = (string) $operation->getSecurity();
            if ($security === '' || str_contains($security, 'PUBLIC_ACCESS')) {
                $public[] = sprintf('%s %s', $module, $route->getPath());
            }
        }

        self::assertSame([], $public, 'une route publique arrive sans établissement : la garde ne la couvre pas');
    }

    /** @param array<string, mixed> $defaults */
    private function guardRefuses(string $name, array $defaults, string $establishmentId): bool
    {
        $request = Request::create('/');
        $request->attributes->add($defaults + ['_route' => $name]);
        $request->headers->set(ContexteEtablissement::HEADER, $establishmentId);

        /** @var RequestStack $stack */
        $stack = static::getContainer()->get('request_stack');
        $stack->push($request);

        try {
            static::getContainer()->get(ModuleGuardListener::class)->onRequest(
                new RequestEvent(static::$kernel, $request, HttpKernelInterface::MAIN_REQUEST),
            );

            return false;
        } catch (NotFoundHttpException) {
            return true;
        } finally {
            $stack->pop();
        }
    }

    /**
     * Les routes des modules masqués, l'appartenance recalculée par le namespace du manifeste.
     *
     * @return list<array{0: string, 1: string, 2: \Symfony\Component\Routing\Route, 3: ?\ApiPlatform\Metadata\Operation}>
     */
    private function maskedRoutes(): array
    {
        $prefixes = [];
        foreach ($this->registry()->all() as $id => $manifest) {
            if ($manifest->capability() !== null && !\in_array($id, self::CORE_WITH_CAPABILITY, true)) {
                $prefixes[(new \ReflectionClass($manifest))->getNamespaceName() . '\\'] = $id;
            }
        }

        /** @var ResourceMetadataCollectionFactoryInterface $metadata */
        $metadata = static::getContainer()->get('api_platform.metadata.resource.metadata_collection_factory');
        $router = static::getContainer()->get(RouterInterface::class);
        $owner = static function (?string $class) use ($prefixes): ?string {
            foreach ($prefixes as $prefix => $id) {
                if ($class !== null && str_starts_with(ltrim($class, '\\'), $prefix)) {
                    return $id;
                }
            }

            return null;
        };

        $routes = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $resource = $route->getDefault('_api_resource_class');
            $controller = $route->getDefault('_controller');
            $operation = null;
            $classes = [$resource, \is_string($controller) ? explode('::', $controller)[0] : null];

            if (\is_string($resource) && \is_string($route->getDefault('_api_operation_name'))) {
                $operation = $metadata->create($resource)->getOperation($route->getDefault('_api_operation_name'));
                $classes[] = \is_string($operation->getProvider()) ? $operation->getProvider() : null;
                $classes[] = \is_string($operation->getProcessor()) ? $operation->getProcessor() : null;
            }

            foreach ($classes as $class) {
                $module = $owner($class);
                if ($module !== null) {
                    $routes[] = [$module, $name, $route, $operation];
                    break;
                }
            }
        }

        return $routes;
    }

    private function registry(): ModuleRegistry
    {
        return static::getContainer()->get(ModuleRegistry::class);
    }
}
