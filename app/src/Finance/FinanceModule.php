<?php

declare(strict_types=1);

namespace App\Finance;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `finance` — racine **partagée** entre FIN-2 (`SupplierInvoice`, qui l'a
 * introduite en premier) et FIN-3 (`ExpenseReport`, §0.10 de son plan) (§0.1 du plan FIN-2).
 * **Premier consommateur réel** de `App\Platform\Event\EventBus` (aucun autre module ne l'utilise
 * directement à ce jour) et **deuxième** module à implémenter `ModuleManifest` après `App\Ocr\OcrModule`.
 *
 * ⚠ **`dependencies(): []` — état transitoire (§7 point 7 du plan FIN-2, repris à l'identique par
 * FIN-3, §7 point 12 de son plan)**, à revoir dès que `compta`/`stock`/`sepa`/`personnel`/
 * `autorisation` implémentent eux-mêmes `ModuleManifest`. `spec-finance-suite.md` §3.1 propose
 * `dependencies(): ['compta', 'stock', 'sepa', 'personnel', 'autorisation']`, mais
 * `ModuleRegistry::assertDependenciesAreResolved()` fait échouer **tout le démarrage applicatif** si
 * une dépendance déclarée n'est pas elle-même un `id` de module enregistré — or aucun de ces modules
 * legacy n'implémente `ModuleManifest` aujourd'hui. Déclarer ces dépendances telles quelles casserait
 * le boot dès que `FinanceModule` serait enregistré. Point à remonter à l'intégrateur A **avant** merge.
 *
 * Événements émis : les 4 événements `supplier_invoice.*` (FIN-2, §0.6 de son plan) **+** les 3
 * événements `expense_report.*` (FIN-3, §0.7 de son plan). Tous catalogués
 * (`CONTRACT/catalogue-evenements.md` — ⚠ seul `expense_report.submitted` y figure littéralement au
 * moment de l'implémentation de FIN-3 ; `expense_report.approved`/`.reimbursed` sont à ajouter au
 * contrat par l'intégrateur avant merge, RG-PLAT-06).
 *
 * ⚠ **Coordination de merge FIN-2/FIN-3 (§7 point 3 du plan FIN-3)** : ce fichier est modifié par les
 * deux lots. Le second lot mergé doit **étendre** (concaténation simple) les tableaux déjà posés par le
 * premier — jamais réécrire la classe.
 */
final class FinanceModule implements ModuleManifest
{
    public function id(): string
    {
        return 'finance';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): string
    {
        return 'finance';
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return [
            'finance.read',
            'finance.supplier_invoice_create',
            'finance.supplier_invoice_approve',
            'finance.supplier_invoice_pay',
            'finance.supplier_invoice_dispute',
            'finance.manage',
            // FIN-3 (§0.10/§0.4 de son plan) — la 4e (`expense_report_approve`) est **essentielle au
            // fonctionnement** de RG-EXP-04, jamais référencée par un `security:` d'opération API :
            // consommée uniquement en interne par `ServiceAutorisation::evaluer()`. Sans elle,
            // `isGranted('PERM', 'finance.expense_report_approve')` renvoie systématiquement `false`
            // pour tout salarié, et la soumission est `Refuse` dès l'étape 1, y compris sous plafond.
            'finance.expense_report_submit',
            'finance.expense_report_read_own',
            'finance.expense_report_post_to_ledger',
            'finance.expense_report_approve',
        ];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        return [
            'supplier_invoice.recorded',
            'supplier_invoice.approved',
            'supplier_invoice.paid',
            'supplier_invoice.disputed',
            // FIN-3 (§0.7 de son plan) — tenant toujours dérivé de `ExpenseReport.establishment`, jamais
            // du contexte HTTP (D6).
            'expense_report.submitted',
            'expense_report.approved',
            'expense_report.reimbursed',
        ];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        return [];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [
            // FIN-3 (§0.10 de son plan, déjà nommées par `spec-finance-suite.md` §3.1).
            'expense_reports',
            'ocr_expense_reports',
        ];
    }

    /** @return list<string> */
    public function routes(): array
    {
        return ['/finance/supplier-invoices', '/finance/expense-reports'];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
