<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Unit;

use App\RevenueRecovery\RevenueRecoveryModule;
use PHPUnit\Framework\TestCase;

/**
 * Contrat `ModuleManifest` (plan-revenue-recovery.md §3/T10), patron
 * `App\Tests\SmartFlow\Unit\SmartFlowModuleManifestTest`/`App\Tests\Platform\Unit\ManifestCatalogueTest`.
 *
 * ⚠ Ce test vit sous `app/tests/RevenueRecovery/**` (périmètre d'écriture imposé à ce lot) plutôt que
 * sous `app/tests/Platform/**` comme suggéré par le plan §5 — à rejoindre par l'intégrateur si souhaité,
 * n'a aucune incidence sur ce qui est vérifié.
 */
final class RevenueRecoveryModuleManifestTest extends TestCase
{
    public function testManifestConstructibleSansArgumentEtPermissionsValides(): void
    {
        $manifest = new RevenueRecoveryModule();

        self::assertSame('revenue_recovery', $manifest->id());
        self::assertSame('0.1.0', $manifest->version());
        self::assertNull($manifest->capability(), 'Service réactif transverse (D22) : Revenue Recovery ne se vend pas.');
        self::assertSame([], $manifest->dependencies());
        self::assertSame(
            ['revenue_recovery.read', 'revenue_recovery.configure', 'revenue_recovery.manage'],
            $manifest->permissions(),
        );
        self::assertSame(
            [],
            $manifest->eventsEmitted(),
            'Émission différée (T9, hors périmètre I1) : les 5 événements revenue_recovery.* ne sont pas encore au catalogue partagé.',
        );
        self::assertSame(
            [
                'booking.cancelled', 'booking.no_show', 'payment.failed', 'payment.incident_reopened',
                'payment.succeeded',
            ],
            $manifest->eventsConsumed(),
        );
        self::assertSame(['revenue_recovery'], $manifest->features());
        self::assertSame([], $manifest->routes(), 'D13 : aucun écran dédié déclaré par ce lot.');
        self::assertSame([], $manifest->settingsSchema());

        foreach ($manifest->permissions() as $permission) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $permission);
        }
    }

    public function testManifestConstructibleSansArgument(): void
    {
        $reflexion = new \ReflectionClass(RevenueRecoveryModule::class);
        $constructeur = $reflexion->getConstructor();

        self::assertSame(0, $constructeur?->getNumberOfRequiredParameters() ?? 0);
    }
}
