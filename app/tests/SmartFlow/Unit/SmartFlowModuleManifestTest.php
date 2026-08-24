<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Unit;

use App\SmartFlow\SmartFlowModule;
use PHPUnit\Framework\TestCase;

/** Contrat `ModuleManifest` (plan-smart-flow.md §0.11), patron `App\Tests\Platform\Unit\ManifestCatalogueTest`. */
final class SmartFlowModuleManifestTest extends TestCase
{
    public function testManifestConstructibleSansArgumentEtPermissionsValides(): void
    {
        $manifest = new SmartFlowModule();

        self::assertSame('smart_flow', $manifest->id());
        self::assertSame('0.1.0', $manifest->version());
        self::assertNull($manifest->capability(), 'Service réactif transverse, D22 : Smart Flow ne vend rien.');
        self::assertSame([], $manifest->dependencies());
        self::assertSame(['smart_flow.reschedule_manage', 'smart_flow.reschedule_read_own'], $manifest->permissions());
        self::assertSame([], $manifest->eventsEmitted(), 'slot.released est posé en I2, absent tant que I2 n\'est pas livré.');
        self::assertSame(['booking.reschedule_requested'], $manifest->eventsConsumed());
        self::assertSame(['no_show_reschedule'], $manifest->features());
        self::assertSame([], $manifest->routes(), 'D13 : aucun écran dédié en I1.');
        self::assertArrayHasKey('compatibleSlotSearchWindowDays', $manifest->settingsSchema());
        self::assertArrayHasKey('rescheduleProposalExpirationDays', $manifest->settingsSchema());
        self::assertArrayHasKey('toleranceLevel', $manifest->settingsSchema());

        foreach ($manifest->permissions() as $permission) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $permission);
        }
    }

    public function testManifestConstructibleSansArgument(): void
    {
        $reflexion = new \ReflectionClass(SmartFlowModule::class);
        $constructeur = $reflexion->getConstructor();

        self::assertSame(0, $constructeur?->getNumberOfRequiredParameters() ?? 0);
    }
}
