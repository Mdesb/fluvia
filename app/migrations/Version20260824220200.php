<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Revenue Recovery (`App\RevenueRecovery`, incrément I1, plan-revenue-recovery.md §4) : table
 * `revenue_recovery_attempt` — une tentative programmée/envoyée dans le cadre d'un `RecoveryCase`.
 *
 * ⚠ Migration écrite à la main (DDL), un seul `CREATE TABLE` additif, aucune table existante touchée.
 * `INDEX idx_recovery_attempt_scheduled (status, scheduled_at)` utilisé par la tâche planifiée
 * `revenue-recovery:attempts:send` (T5).
 */
final class Version20260824220200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Revenue Recovery (App\\RevenueRecovery, I1) : revenue_recovery_attempt (RG-RR-03/08).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE revenue_recovery_attempt ('
            . 'id BINARY(16) NOT NULL, '
            . 'recovery_case_id BINARY(16) NOT NULL, '
            . 'step_index SMALLINT NOT NULL, '
            . 'scheduled_at DATETIME NOT NULL, '
            . 'sent_at DATETIME DEFAULT NULL, '
            . "channel VARCHAR(8) DEFAULT 'email' NOT NULL, "
            . "status VARCHAR(10) DEFAULT 'pending' NOT NULL, "
            . 'skip_reason VARCHAR(32) DEFAULT NULL, '
            . 'INDEX idx_recovery_attempt_case (recovery_case_id), '
            . 'INDEX idx_recovery_attempt_scheduled (status, scheduled_at), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE revenue_recovery_attempt ADD CONSTRAINT FK_REVENUE_RECOVERY_ATTEMPT_CASE FOREIGN KEY (recovery_case_id) REFERENCES revenue_recovery_case (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE revenue_recovery_attempt DROP FOREIGN KEY FK_REVENUE_RECOVERY_ATTEMPT_CASE');
        $this->addSql('DROP TABLE revenue_recovery_attempt');
    }
}
