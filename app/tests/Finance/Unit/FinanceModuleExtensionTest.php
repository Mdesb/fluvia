<?php

declare(strict_types=1);

namespace App\Tests\Finance\Unit;

use App\Finance\FinanceModule;
use PHPUnit\Framework\TestCase;

/**
 * T5 du plan (§0.7, addendum FIN-4 « alertes de trésorerie proactives ») — `treasury.threshold_breached`
 * figure dans `FinanceModule::eventsEmitted()`, **sans supprimer** les 9 événements déjà déclarés
 * (FIN-2/FIN-3/FIN-4).
 */
final class FinanceModuleExtensionTest extends TestCase
{
    public function testThresholdBreachedPresentSansRegression(): void
    {
        $manifest = new FinanceModule();

        self::assertContains('treasury.threshold_breached', $manifest->eventsEmitted());

        // Les 9 événements déjà déclarés avant cet addendum restent tous présents.
        foreach ([
            'supplier_invoice.recorded',
            'supplier_invoice.approved',
            'supplier_invoice.paid',
            'supplier_invoice.disputed',
            'expense_report.submitted',
            'expense_report.approved',
            'expense_report.reimbursed',
            'treasury.reconciliation_completed',
            'treasury.discrepancy_detected',
        ] as $evenement) {
            self::assertContains($evenement, $manifest->eventsEmitted());
        }

        self::assertCount(10, $manifest->eventsEmitted(), 'Exactement 9 événements préexistants + 1 nouveau.');
    }
}
