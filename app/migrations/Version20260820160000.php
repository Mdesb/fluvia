<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Finance\ExpenseReport\Entity\ExpenseReport` (lot FIN-3) — nouvelle table, brique entièrement
 * nouvelle (aucune table existante modifiée). `establishment_id` est l'ancre de périmètre (D6/D8,
 * §0.2/§1 du plan) — colonne directe, pas seulement via `business_profile_id`. Horodatage choisi
 * strictement après les migrations FIN-2 (`Version20260820090000`…`090300`) et après toutes les
 * migrations existantes au moment de l'implémentation (jusqu'à `Version20260820151917`).
 */
final class Version20260820160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance\\ExpenseReport (FIN-3) : nouvelle table finance_expense_report (note de frais).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_expense_report (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                business_profile_id BINARY(16) NOT NULL,
                employee_id BINARY(16) NOT NULL,
                status VARCHAR(16) DEFAULT 'draft' NOT NULL,
                total_amount NUMERIC(12, 2) DEFAULT '0.00' NOT NULL,
                escalation_request_id BINARY(16) DEFAULT NULL,
                rejection_reason LONGTEXT DEFAULT NULL,
                ledger_entry_id BINARY(16) DEFAULT NULL,
                submitted_at DATETIME DEFAULT NULL,
                approved_at DATETIME DEFAULT NULL,
                reimbursed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                created_by_id BINARY(16) DEFAULT NULL,
                INDEX idx_expense_report_establishment_status (establishment_id, status),
                INDEX idx_expense_report_employee (employee_id),
                INDEX idx_expense_report_business_profile (business_profile_id),
                INDEX idx_expense_report_escalation_request (escalation_request_id),
                INDEX idx_expense_report_ledger_entry (ledger_entry_id),
                INDEX idx_expense_report_created_by (created_by_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_expense_report ADD CONSTRAINT FK_FIN_EXP_ESTABLISHMENT FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE finance_expense_report ADD CONSTRAINT FK_FIN_EXP_BUSINESS_PROFILE FOREIGN KEY (business_profile_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE finance_expense_report ADD CONSTRAINT FK_FIN_EXP_EMPLOYEE FOREIGN KEY (employee_id) REFERENCES personnel_employe (id)');
        $this->addSql('ALTER TABLE finance_expense_report ADD CONSTRAINT FK_FIN_EXP_ESCALATION_REQUEST FOREIGN KEY (escalation_request_id) REFERENCES atz_demande_escalade (id)');
        $this->addSql('ALTER TABLE finance_expense_report ADD CONSTRAINT FK_FIN_EXP_LEDGER_ENTRY FOREIGN KEY (ledger_entry_id) REFERENCES compta_ecriture_comptable (id)');
        $this->addSql('ALTER TABLE finance_expense_report ADD CONSTRAINT FK_FIN_EXP_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES sec_utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_expense_report');
    }
}
