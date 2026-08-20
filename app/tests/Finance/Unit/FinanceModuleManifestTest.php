<?php

declare(strict_types=1);

namespace App\Tests\Finance\Unit;

use App\Finance\FinanceModule;
use App\Platform\Event\EventName;
use PHPUnit\Framework\TestCase;

/**
 * Contrat `ModuleManifest` (patron `ManifestCatalogueTest` existant, `tests/Platform/Unit`) — vérifie
 * que le manifeste du module `finance` est constructible sans argument et que ses permissions/
 * événements respectent les formats attendus par le noyau (D5).
 */
final class FinanceModuleManifestTest extends TestCase
{
    public function testManifestConstructibleSansArgument(): void
    {
        $constructeur = (new \ReflectionClass(FinanceModule::class))->getConstructor();

        self::assertSame(0, $constructeur?->getNumberOfRequiredParameters() ?? 0);

        $manifest = new FinanceModule();
        self::assertSame('finance', $manifest->id());
        self::assertSame('finance', $manifest->capability());
    }

    public function testPermissionsRespectentLeFormatModuleAction(): void
    {
        $manifest = new FinanceModule();

        self::assertNotEmpty($manifest->permissions());
        foreach ($manifest->permissions() as $permission) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $permission);
            self::assertStringStartsWith('finance.', $permission);
        }
    }

    public function testEvenementsEmisRespectentLeFormatDuCatalogue(): void
    {
        $manifest = new FinanceModule();

        self::assertNotEmpty($manifest->eventsEmitted());
        foreach ($manifest->eventsEmitted() as $evenement) {
            self::assertMatchesRegularExpression(EventName::PATTERN, $evenement);
            self::assertStringStartsWith('supplier_invoice.', $evenement);
        }

        self::assertSame([], $manifest->eventsConsumed(), 'Ce lot ne consomme aucun événement (§0.4 du plan : appel direct, pas un abonnement).');
    }

    /**
     * §7 point 7 du plan — état transitoire documenté : `dependencies()` doit rester vide tant que
     * `compta`/`stock`/`sepa`/`personnel`/`autorisation` n'implémentent pas eux-mêmes `ModuleManifest`,
     * sous peine de faire échouer `ModuleRegistry::assertDependenciesAreResolved()` au démarrage.
     */
    public function testDependenciesTransitoirementVide(): void
    {
        $manifest = new FinanceModule();

        self::assertSame([], $manifest->dependencies());
    }
}
