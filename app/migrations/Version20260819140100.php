<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Compta\Entity\ExpenseAccountMapping` (lot FIN-1, US-L4-12, RG-M6-12) — nouvelle table, nom
 * anglais (cohérent §0 de `spec-finance-suite.md`, artefacts nouveaux du module `compta` existant).
 * Mapping paramétrable « nature de charge -> compte de charge + taux de TVA déductible », symétrique
 * de `compta_mapping_comptable` côté produits. Extension additive : nouvelle table, aucune migration
 * destructive sur l'existant.
 */
final class Version20260819140100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Compta (FIN-1) : nouvelle table compta_expense_account_mapping (mapping nature de charge -> compte + taux TVA déductible).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE compta_expense_account_mapping (
                id BINARY(16) NOT NULL,
                business_profile_id BINARY(16) NOT NULL,
                expense_nature_code VARCHAR(64) NOT NULL,
                expense_account_id BINARY(16) NOT NULL,
                deductible_vat_rate_id BINARY(16) NOT NULL,
                active TINYINT(1) DEFAULT 1 NOT NULL,
                INDEX idx_expense_mapping_business_profile (business_profile_id),
                INDEX idx_expense_mapping_expense_account (expense_account_id),
                INDEX idx_expense_mapping_deductible_vat_rate (deductible_vat_rate_id),
                UNIQUE INDEX uniq_expense_mapping_profil_nature (business_profile_id, expense_nature_code),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE compta_expense_account_mapping ADD CONSTRAINT FK_EXPENSE_MAPPING_BUSINESS_PROFILE FOREIGN KEY (business_profile_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE compta_expense_account_mapping ADD CONSTRAINT FK_EXPENSE_MAPPING_EXPENSE_ACCOUNT FOREIGN KEY (expense_account_id) REFERENCES compta_compte_comptable (id)');
        $this->addSql('ALTER TABLE compta_expense_account_mapping ADD CONSTRAINT FK_EXPENSE_MAPPING_DEDUCTIBLE_VAT_RATE FOREIGN KEY (deductible_vat_rate_id) REFERENCES compta_taux_tva (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE compta_expense_account_mapping');
    }
}
