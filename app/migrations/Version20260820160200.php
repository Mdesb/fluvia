<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Finance\ExpenseReport\Entity\Reimbursement` (lot FIN-3) — nouvelle table, remboursement
 * déclaratif d'une note de frais (§0.6 du plan). `UNIQUE(expense_report_id)` : un seul remboursement
 * possible par note, remboursement partiel non retenu v1.
 */
final class Version20260820160200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance\\ExpenseReport (FIN-3) : nouvelle table finance_expense_reimbursement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_expense_reimbursement (
                id BINARY(16) NOT NULL,
                expense_report_id BINARY(16) NOT NULL,
                date DATE NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                method_id BINARY(16) NOT NULL,
                reference VARCHAR(64) DEFAULT NULL,
                ledger_entry_id BINARY(16) NOT NULL,
                reconciliation_code VARCHAR(36) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                created_by_id BINARY(16) DEFAULT NULL,
                UNIQUE INDEX uniq_expense_reimbursement_report (expense_report_id),
                INDEX idx_expense_reimbursement_method (method_id),
                INDEX idx_expense_reimbursement_ledger_entry (ledger_entry_id),
                INDEX idx_expense_reimbursement_created_by (created_by_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_expense_reimbursement ADD CONSTRAINT FK_FIN_EXPR_REPORT FOREIGN KEY (expense_report_id) REFERENCES finance_expense_report (id)');
        $this->addSql('ALTER TABLE finance_expense_reimbursement ADD CONSTRAINT FK_FIN_EXPR_METHOD FOREIGN KEY (method_id) REFERENCES compta_moyen_paiement (id)');
        $this->addSql('ALTER TABLE finance_expense_reimbursement ADD CONSTRAINT FK_FIN_EXPR_LEDGER_ENTRY FOREIGN KEY (ledger_entry_id) REFERENCES compta_ecriture_comptable (id)');
        $this->addSql('ALTER TABLE finance_expense_reimbursement ADD CONSTRAINT FK_FIN_EXPR_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES sec_utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_expense_reimbursement');
    }
}
