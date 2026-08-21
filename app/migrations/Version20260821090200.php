<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Finance\Treasury\Entity\BankStatementLine` (lot FIN-4) — nouvelle table. `matched_ledger_line_id`
 * pointe une `LigneEcriture` (512) précise, jamais une `EcritureComptable` (§1 du plan) ; aucune écriture
 * comptable n'est créée par ce lot, seule table du noyau Compta touchée en écriture : le lettrage
 * existant (`compta_lettrage_ecriture`), via les méthodes publiques de `LettrageHandler` (§0.6).
 */
final class Version20260821090200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance\\Treasury (FIN-4) : nouvelle table finance_treasury_bank_statement_line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_treasury_bank_statement_line (
                id BINARY(16) NOT NULL,
                statement_import_id BINARY(16) NOT NULL,
                operation_date DATE NOT NULL,
                label VARCHAR(255) NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                reference VARCHAR(140) DEFAULT NULL,
                status VARCHAR(10) DEFAULT 'unmatched' NOT NULL,
                suggested_ledger_line_id BINARY(16) DEFAULT NULL,
                matched_ledger_line_id BINARY(16) DEFAULT NULL,
                reconciliation_code VARCHAR(36) DEFAULT NULL,
                ignored_reason LONGTEXT DEFAULT NULL,
                discrepancy_notified_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_treasury_statement_line_import_date (statement_import_id, operation_date),
                INDEX idx_treasury_statement_line_status (status),
                INDEX idx_treasury_statement_line_suggested_ledger (suggested_ledger_line_id),
                INDEX idx_treasury_statement_line_matched_ledger (matched_ledger_line_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_treasury_bank_statement_line ADD CONSTRAINT FK_FIN_TRE_BSL_STATEMENT_IMPORT FOREIGN KEY (statement_import_id) REFERENCES finance_treasury_bank_statement_import (id)');
        $this->addSql('ALTER TABLE finance_treasury_bank_statement_line ADD CONSTRAINT FK_FIN_TRE_BSL_SUGGESTED_LEDGER FOREIGN KEY (suggested_ledger_line_id) REFERENCES compta_ligne_ecriture (id)');
        $this->addSql('ALTER TABLE finance_treasury_bank_statement_line ADD CONSTRAINT FK_FIN_TRE_BSL_MATCHED_LEDGER FOREIGN KEY (matched_ledger_line_id) REFERENCES compta_ligne_ecriture (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_treasury_bank_statement_line');
    }
}
