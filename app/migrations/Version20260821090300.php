<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Finance\Treasury\Entity\TreasurySettings` (lot FIN-4) — nouvelle table, un réglage
 * (`unmatchedAlertDelayDays`/`matchingWindowDays`) par établissement.
 */
final class Version20260821090300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance\\Treasury (FIN-4) : nouvelle table finance_treasury_settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_treasury_settings (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                unmatched_alert_delay_days INT DEFAULT 15 NOT NULL,
                matching_window_days INT DEFAULT 5 NOT NULL,
                UNIQUE INDEX uniq_treasury_settings_establishment (establishment_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_treasury_settings ADD CONSTRAINT FK_FIN_TRE_SET_ESTABLISHMENT FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_treasury_settings');
    }
}
