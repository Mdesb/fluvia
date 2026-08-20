<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Finance\ExpenseReport\Entity\ExpenseLine` (lot FIN-3) — nouvelle table, ligne de dépense d'une
 * note de frais (créée/éditée séparément de la note, même patron que `finance_supplier_invoice_line`).
 */
final class Version20260820160100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance\\ExpenseReport (FIN-3) : nouvelle table finance_expense_line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_expense_line (
                id BINARY(16) NOT NULL,
                expense_report_id BINARY(16) NOT NULL,
                expense_nature_code VARCHAR(64) NOT NULL,
                expense_date DATE NOT NULL,
                amount_incl_tax NUMERIC(12, 2) NOT NULL,
                vat_rate_id BINARY(16) DEFAULT NULL,
                amount_excl_tax NUMERIC(12, 2) NOT NULL,
                vat_amount NUMERIC(12, 2) NOT NULL,
                receipt_file_name VARCHAR(255) DEFAULT NULL,
                receipt_mime_type VARCHAR(100) DEFAULT NULL,
                receipt_size INT DEFAULT NULL,
                receipt_url VARCHAR(500) DEFAULT NULL,
                ocr_extraction_id BINARY(16) DEFAULT NULL,
                description VARCHAR(255) DEFAULT NULL,
                INDEX idx_expense_line_report (expense_report_id),
                INDEX idx_expense_line_vat_rate (vat_rate_id),
                INDEX idx_expense_line_ocr_extraction (ocr_extraction_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_expense_line ADD CONSTRAINT FK_FIN_EXPL_REPORT FOREIGN KEY (expense_report_id) REFERENCES finance_expense_report (id)');
        $this->addSql('ALTER TABLE finance_expense_line ADD CONSTRAINT FK_FIN_EXPL_VAT_RATE FOREIGN KEY (vat_rate_id) REFERENCES compta_taux_tva (id)');
        $this->addSql('ALTER TABLE finance_expense_line ADD CONSTRAINT FK_FIN_EXPL_OCR_EXTRACTION FOREIGN KEY (ocr_extraction_id) REFERENCES ocr_extraction_attempt (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_expense_line');
    }
}
