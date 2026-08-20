<?php

declare(strict_types=1);

namespace App\Finance;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `finance` (lot FIN-2, `SupplierInvoice`) — racine du module (§0.1 du plan).
 * **Premier consommateur réel** de `App\Platform\Event\EventBus` (aucun autre module ne l'utilise
 * directement à ce jour) et **deuxième** module à implémenter `ModuleManifest` après `App\Ocr\OcrModule`.
 *
 * ⚠ **`dependencies(): []` — état transitoire (§7 point 7 du plan)**, à revoir dès que
 * `compta`/`stock`/`sepa`/`personnel`/`autorisation` implémentent eux-mêmes `ModuleManifest`.
 * `spec-finance-suite.md` §3.1 propose `dependencies(): ['compta', 'stock', 'sepa', 'personnel',
 * 'autorisation']`, mais `ModuleRegistry::assertDependenciesAreResolved()` fait échouer **tout le
 * démarrage applicatif** si une dépendance déclarée n'est pas elle-même un `id` de module enregistré —
 * or aucun de ces modules legacy n'implémente `ModuleManifest` aujourd'hui. Déclarer ces dépendances
 * telles quelles casserait le boot dès que `FinanceModule` serait enregistré. Point à remonter à
 * l'intégrateur A **avant** merge.
 *
 * Événements émis : les 4 événements `supplier_invoice.*` réellement publiés par ce lot (§0.6 du plan).
 * `supplier_invoice.recorded` est déjà catalogué (`CONTRACT/catalogue-evenements.md`) ; les 3 autres
 * (`approved`/`paid`/`disputed`) ne le sont **pas encore** — écart signalé à l'intégrateur (rapport
 * final), pas une omission silencieuse.
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
        return [];
    }

    /** @return list<string> */
    public function routes(): array
    {
        return ['/finance/supplier-invoices'];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
