<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Finance\SupplierInvoice\Entity\SupplierInvoice` (lot FIN-2) — nouvelle table, brique
 * entièrement nouvelle (aucune table existante modifiée). `establishment_id` est l'ancre de périmètre
 * (D6/D8, §0.2/§1 du plan) — colonne directe, pas seulement via `business_profile_id`.
 */
final class Version20260820090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance (FIN-2) : nouvelle table finance_supplier_invoice (facture fournisseur).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_supplier_invoice (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                business_profile_id BINARY(16) NOT NULL,
                supplier_id BINARY(16) NOT NULL,
                nature VARCHAR(12) DEFAULT 'invoice' NOT NULL,
                supplier_invoice_number VARCHAR(64) NOT NULL,
                invoice_date DATE NOT NULL,
                due_date DATE NOT NULL,
                status VARCHAR(16) DEFAULT 'draft' NOT NULL,
                purchase_order_id BINARY(16) DEFAULT NULL,
                goods_receipt_id BINARY(16) DEFAULT NULL,
                amount_excl_tax NUMERIC(12, 2) DEFAULT '0.00' NOT NULL,
                amount_incl_tax NUMERIC(12, 2) DEFAULT '0.00' NOT NULL,
                attachment_file_name VARCHAR(255) DEFAULT NULL,
                attachment_mime_type VARCHAR(100) DEFAULT NULL,
                attachment_size INT DEFAULT NULL,
                attachment_url VARCHAR(500) DEFAULT NULL,
                ocr_extraction_id BINARY(16) DEFAULT NULL,
                source VARCHAR(8) DEFAULT 'manual' NOT NULL,
                ledger_entry_id BINARY(16) DEFAULT NULL,
                dispute_reason LONGTEXT DEFAULT NULL,
                dispute_resolution_reason LONGTEXT DEFAULT NULL,
                corrects_invoice_id BINARY(16) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                created_by_id BINARY(16) DEFAULT NULL,
                INDEX idx_supplier_invoice_establishment_status (establishment_id, status),
                INDEX idx_supplier_invoice_supplier (supplier_id),
                INDEX idx_supplier_invoice_business_profile (business_profile_id),
                INDEX idx_supplier_invoice_purchase_order (purchase_order_id),
                INDEX idx_supplier_invoice_goods_receipt (goods_receipt_id),
                INDEX idx_supplier_invoice_ocr_extraction (ocr_extraction_id),
                INDEX idx_supplier_invoice_ledger_entry (ledger_entry_id),
                INDEX idx_supplier_invoice_corrects_invoice (corrects_invoice_id),
                INDEX idx_supplier_invoice_created_by (created_by_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_supplier_invoice ADD CONSTRAINT FK_FIN_SINV_ESTABLISHMENT FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE finance_supplier_invoice ADD CONSTRAINT FK_FIN_SINV_BUSINESS_PROFILE FOREIGN KEY (business_profile_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE finance_supplier_invoice ADD CONSTRAINT FK_FIN_SINV_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES stk_fournisseur (id)');
        $this->addSql('ALTER TABLE finance_supplier_invoice ADD CONSTRAINT FK_FIN_SINV_PURCHASE_ORDER FOREIGN KEY (purchase_order_id) REFERENCES stk_commande_achat (id)');
        $this->addSql('ALTER TABLE finance_supplier_invoice ADD CONSTRAINT FK_FIN_SINV_GOODS_RECEIPT FOREIGN KEY (goods_receipt_id) REFERENCES stk_reception_achat (id)');
        $this->addSql('ALTER TABLE finance_supplier_invoice ADD CONSTRAINT FK_FIN_SINV_OCR_EXTRACTION FOREIGN KEY (ocr_extraction_id) REFERENCES ocr_extraction_attempt (id)');
        $this->addSql('ALTER TABLE finance_supplier_invoice ADD CONSTRAINT FK_FIN_SINV_LEDGER_ENTRY FOREIGN KEY (ledger_entry_id) REFERENCES compta_ecriture_comptable (id)');
        $this->addSql('ALTER TABLE finance_supplier_invoice ADD CONSTRAINT FK_FIN_SINV_CORRECTS_INVOICE FOREIGN KEY (corrects_invoice_id) REFERENCES finance_supplier_invoice (id)');
        $this->addSql('ALTER TABLE finance_supplier_invoice ADD CONSTRAINT FK_FIN_SINV_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES sec_utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_supplier_invoice DROP FOREIGN KEY FK_FIN_SINV_CORRECTS_INVOICE');
        $this->addSql('DROP TABLE finance_supplier_invoice');
    }
}
