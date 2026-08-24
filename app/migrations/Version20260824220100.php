<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Revenue Recovery (`App\RevenueRecovery`, incrément I1, plan-revenue-recovery.md §4) : table
 * `revenue_recovery_case` — dossier de relance ouvert par occurrence d'un déclencheur (RG-RR-02).
 *
 * ⚠ Migration écrite à la main (DDL), un seul `CREATE TABLE` additif, aucune table existante touchée.
 * `amount_cents` **NULL** (pas toujours une dette, spec §7). `stop_reason` LONGTEXT NULL (requis
 * applicativement, pas en base, si `status = stopped` — RG-RR-05, vérifié par
 * `RecoveryEngine::stopManually()`).
 */
final class Version20260824220100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Revenue Recovery (App\\RevenueRecovery, I1) : revenue_recovery_case (RG-RR-01..09).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE revenue_recovery_case ('
            . 'id BINARY(16) NOT NULL, '
            . 'establishment_id BINARY(16) NOT NULL, '
            . 'trigger_type VARCHAR(32) NOT NULL, '
            . 'subject_type VARCHAR(32) NOT NULL, '
            . 'subject_ref VARCHAR(64) NOT NULL, '
            . 'amount_cents INT DEFAULT NULL, '
            . "status VARCHAR(16) DEFAULT 'active' NOT NULL, "
            . 'sequence_id BINARY(16) NOT NULL, '
            . 'opened_at DATETIME NOT NULL, '
            . 'resolved_at DATETIME DEFAULT NULL, '
            . 'stopped_at DATETIME DEFAULT NULL, '
            . 'stop_reason LONGTEXT DEFAULT NULL, '
            . 'stopped_by_id BINARY(16) DEFAULT NULL, '
            . 'INDEX idx_recovery_case_establishment_status (establishment_id, status), '
            . 'INDEX idx_recovery_case_subject (establishment_id, trigger_type, subject_type, subject_ref), '
            . 'INDEX idx_recovery_case_sequence (sequence_id), '
            . 'INDEX idx_recovery_case_stopped_by (stopped_by_id), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE revenue_recovery_case ADD CONSTRAINT FK_REVENUE_RECOVERY_CASE_ETAB FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE revenue_recovery_case ADD CONSTRAINT FK_REVENUE_RECOVERY_CASE_SEQUENCE FOREIGN KEY (sequence_id) REFERENCES revenue_recovery_sequence (id)');
        $this->addSql('ALTER TABLE revenue_recovery_case ADD CONSTRAINT FK_REVENUE_RECOVERY_CASE_STOPPED_BY FOREIGN KEY (stopped_by_id) REFERENCES sec_utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE revenue_recovery_case DROP FOREIGN KEY FK_REVENUE_RECOVERY_CASE_ETAB');
        $this->addSql('ALTER TABLE revenue_recovery_case DROP FOREIGN KEY FK_REVENUE_RECOVERY_CASE_SEQUENCE');
        $this->addSql('ALTER TABLE revenue_recovery_case DROP FOREIGN KEY FK_REVENUE_RECOVERY_CASE_STOPPED_BY');
        $this->addSql('DROP TABLE revenue_recovery_case');
    }
}
