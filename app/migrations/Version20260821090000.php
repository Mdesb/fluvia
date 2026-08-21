<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Finance\Treasury\Entity\BankAccount` (lot FIN-4) — nouvelle table, brique entièrement nouvelle
 * (aucune table existante modifiée). `establishment_id` est l'ancre de périmètre (D6/D8, §0.2 du plan)
 * — colonne directe. `iban_cipher` (coffre SEPA réutilisé, §0.3 du plan) n'est jamais exposé en API.
 */
final class Version20260821090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance\\Treasury (FIN-4) : nouvelle table finance_treasury_bank_account (compte bancaire).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_treasury_bank_account (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                ledger_account_id BINARY(16) DEFAULT NULL,
                iban_cipher LONGTEXT DEFAULT NULL,
                iban_last4 VARCHAR(4) DEFAULT '' NOT NULL,
                bic VARCHAR(11) DEFAULT '' NOT NULL,
                label VARCHAR(140) NOT NULL,
                opening_balance NUMERIC(12, 2) DEFAULT '0.00' NOT NULL,
                opening_balance_date DATE NOT NULL,
                active TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL,
                created_by_id BINARY(16) DEFAULT NULL,
                INDEX idx_treasury_bank_account_establishment (establishment_id),
                INDEX idx_treasury_bank_account_ledger_account (ledger_account_id),
                INDEX idx_treasury_bank_account_created_by (created_by_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_treasury_bank_account ADD CONSTRAINT FK_FIN_TRE_BACC_ESTABLISHMENT FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE finance_treasury_bank_account ADD CONSTRAINT FK_FIN_TRE_BACC_LEDGER_ACCOUNT FOREIGN KEY (ledger_account_id) REFERENCES compta_compte_comptable (id)');
        $this->addSql('ALTER TABLE finance_treasury_bank_account ADD CONSTRAINT FK_FIN_TRE_BACC_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES sec_utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_treasury_bank_account');
    }
}
