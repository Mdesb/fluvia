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
        self::assertSame(
            ['smart_flow.read', 'smart_flow.reschedule_manage', 'smart_flow.reschedule_read_own'],
            $manifest->permissions(),
            'I2 (T11) ajoute smart_flow.read (lecture SlotWaitlistEntry, §2 du plan).',
        );
        self::assertSame(['slot.released'], $manifest->eventsEmitted(), 'I2 (T11) : slot.released, produit par SlotFreedListener (RG-SF-03).');
        self::assertSame(
            ['booking.reschedule_requested', 'booking.cancelled', 'booking.no_show'],
            $manifest->eventsConsumed(),
            'I2 (T11) ajoute booking.cancelled/booking.no_show (SlotFreedListener, RG-SF-01..04).',
        );
        self::assertSame(['no_show_reschedule', 'slot_recovery'], $manifest->features(), 'I2 (T11) ajoute la feature slot_recovery.');
        self::assertSame([], $manifest->routes(), 'D13 : aucun écran dédié.');
        self::assertArrayHasKey('compatibleSlotSearchWindowDays', $manifest->settingsSchema());
        self::assertArrayHasKey('rescheduleProposalExpirationDays', $manifest->settingsSchema());
        self::assertArrayHasKey('slotWaitlistPromotionExpirationMinutes', $manifest->settingsSchema());
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
