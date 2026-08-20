<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Finance\SupplierInvoice\Entity\ReconciliationSettings` (lot FIN-2) — nouvelle table, un réglage
 * de seuil de rapprochement (RG-SINV-03) par profil exploitant.
 */
final class Version20260820090300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance (FIN-2) : nouvelle table finance_reconciliation_settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_reconciliation_settings (
                id BINARY(16) NOT NULL,
                business_profile_id BINARY(16) NOT NULL,
                tolerance_threshold_percent NUMERIC(5, 2) DEFAULT '5.00' NOT NULL,
                UNIQUE INDEX uniq_reconciliation_settings_profil (business_profile_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_reconciliation_settings ADD CONSTRAINT FK_FIN_RSET_BUSINESS_PROFILE FOREIGN KEY (business_profile_id) REFERENCES compta_profil_exploitant (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_reconciliation_settings');
    }
}
