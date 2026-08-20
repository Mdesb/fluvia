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
            // Fichier partagé FIN-2/FIN-3 (§0.10/§7 point 3 du plan FIN-3) : deux domaines légitimes.
            self::assertTrue(
                str_starts_with($evenement, 'supplier_invoice.') || str_starts_with($evenement, 'expense_report.'),
                sprintf('Événement inattendu hors des domaines supplier_invoice./expense_report. : « %s ».', $evenement),
            );
        }

        self::assertSame([], $manifest->eventsConsumed(), 'Ni FIN-2 ni FIN-3 ne consomment un événement (appel direct, pas un abonnement).');
    }

    /** §0.10/§0.4 du plan FIN-3 — non-régression sur le fichier partagé : les 4 permissions et 3 événements de cette brique sont bien présents, sans supprimer ceux de FIN-2. */
    public function testPermissionsEtEvenementsExpenseReportPresents(): void
    {
        $manifest = new FinanceModule();

        foreach ([
            'finance.expense_report_submit',
            'finance.expense_report_read_own',
            'finance.expense_report_post_to_ledger',
            'finance.expense_report_approve',
        ] as $permission) {
            self::assertContains($permission, $manifest->permissions());
        }

        foreach ([
            'expense_report.submitted',
            'expense_report.approved',
            'expense_report.reimbursed',
        ] as $evenement) {
            self::assertContains($evenement, $manifest->eventsEmitted());
        }

        foreach (['expense_reports', 'ocr_expense_reports'] as $feature) {
            self::assertContains($feature, $manifest->features());
        }

        // Non-régression FIN-2 : rien supprimé par l'extension FIN-3.
        self::assertContains('finance.supplier_invoice_approve', $manifest->permissions());
        self::assertContains('supplier_invoice.recorded', $manifest->eventsEmitted());
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
