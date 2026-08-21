<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Finance\Treasury\Entity\BankStatementImport` (lot FIN-4) — nouvelle table. Idempotence niveau
 * fichier (§0.5 du plan, CA-2) : `UNIQUE(bank_account_id, content_hash)`.
 */
final class Version20260821090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance\\Treasury (FIN-4) : nouvelle table finance_treasury_bank_statement_import.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_treasury_bank_statement_import (
                id BINARY(16) NOT NULL,
                bank_account_id BINARY(16) NOT NULL,
                format VARCHAR(8) NOT NULL,
                file_name VARCHAR(255) DEFAULT NULL,
                file_mime_type VARCHAR(100) DEFAULT NULL,
                file_size INT DEFAULT NULL,
                content_hash VARCHAR(64) DEFAULT NULL,
                imported_at DATETIME NOT NULL,
                status VARCHAR(10) DEFAULT 'imported' NOT NULL,
                error_message LONGTEXT DEFAULT NULL,
                lines_created INT DEFAULT 0 NOT NULL,
                lines_skipped INT DEFAULT 0 NOT NULL,
                created_by_id BINARY(16) DEFAULT NULL,
                UNIQUE INDEX uniq_treasury_statement_import_hash (bank_account_id, content_hash),
                INDEX idx_treasury_statement_import_bank_account (bank_account_id),
                INDEX idx_treasury_statement_import_created_by (created_by_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_treasury_bank_statement_import ADD CONSTRAINT FK_FIN_TRE_BSI_BANK_ACCOUNT FOREIGN KEY (bank_account_id) REFERENCES finance_treasury_bank_account (id)');
        $this->addSql('ALTER TABLE finance_treasury_bank_statement_import ADD CONSTRAINT FK_FIN_TRE_BSI_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES sec_utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_treasury_bank_statement_import');
    }
}
