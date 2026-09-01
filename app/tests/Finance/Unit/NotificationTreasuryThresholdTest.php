<?php

declare(strict_types=1);

namespace App\Tests\Finance\Unit;

use App\Platform\Enum\NotificationSeverity;
use App\Platform\Notification\NotificationRule;
use PHPUnit\Framework\TestCase;

/**
 * §4.5 de la spec, §0.8 du plan — `treasury.threshold_breached` est admis avec la gravité `Warning`
 * (« se planifie », pas `Critical` : un franchissement projeté laisse par construction au moins un jour
 * d'anticipation).
 */
final class NotificationTreasuryThresholdTest extends TestCase
{
    public function testGraviteWarning(): void
    {
        $regle = NotificationRule::forEvent('treasury.threshold_breached');

        self::assertNotNull($regle, '`treasury.threshold_breached` doit être admis par NotificationRule.');
        self::assertSame(NotificationSeverity::Warning, $regle->severity);
        self::assertSame('finance', $regle->module);
        self::assertSame('read', $regle->action);
        self::assertSame('finance', $regle->screen);
        self::assertSame('alert', $regle->paramName);
        self::assertSame('projected_breach_date', $regle->anchorKey);
    }

    /** Non-régression — la règle voisine `treasury.discrepancy_detected` n'est pas affectée par cet ajout. */
    public function testDiscrepancyDetectedResteCritique(): void
    {
        $regle = NotificationRule::forEvent('treasury.discrepancy_detected');

        self::assertNotNull($regle);
        self::assertSame(NotificationSeverity::Critical, $regle->severity);
        self::assertSame('compta', $regle->module);
        self::assertSame('lire', $regle->action);
    }
}
