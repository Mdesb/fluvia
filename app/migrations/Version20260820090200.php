<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Finance\SupplierInvoice\Entity\SupplierPayment` (lot FIN-2) — nouvelle table, règlement d'une
 * facture fournisseur (§0.8 du plan : chaque règlement porte sa propre écriture, lettrage différé).
 */
final class Version20260820090200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance (FIN-2) : nouvelle table finance_supplier_payment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_supplier_payment (
                id BINARY(16) NOT NULL,
                supplier_invoice_id BINARY(16) NOT NULL,
                date DATE NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                payment_method_id BINARY(16) NOT NULL,
                reference VARCHAR(64) DEFAULT NULL,
                ledger_entry_id BINARY(16) NOT NULL,
                reconciliation_code VARCHAR(36) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                created_by_id BINARY(16) DEFAULT NULL,
                INDEX idx_supplier_payment_invoice (supplier_invoice_id),
                INDEX idx_supplier_payment_payment_method (payment_method_id),
                INDEX idx_supplier_payment_ledger_entry (ledger_entry_id),
                INDEX idx_supplier_payment_created_by (created_by_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_supplier_payment ADD CONSTRAINT FK_FIN_SPAY_INVOICE FOREIGN KEY (supplier_invoice_id) REFERENCES finance_supplier_invoice (id)');
        $this->addSql('ALTER TABLE finance_supplier_payment ADD CONSTRAINT FK_FIN_SPAY_PAYMENT_METHOD FOREIGN KEY (payment_method_id) REFERENCES compta_moyen_paiement (id)');
        $this->addSql('ALTER TABLE finance_supplier_payment ADD CONSTRAINT FK_FIN_SPAY_LEDGER_ENTRY FOREIGN KEY (ledger_entry_id) REFERENCES compta_ecriture_comptable (id)');
        $this->addSql('ALTER TABLE finance_supplier_payment ADD CONSTRAINT FK_FIN_SPAY_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES sec_utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_supplier_payment');
    }
}
