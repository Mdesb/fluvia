<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module Revenue Recovery (`App\RevenueRecovery`, incrément I1, plan-revenue-recovery.md §4) : table
 * `revenue_recovery_sequence` + seed des permissions `revenue_recovery.read`/
 * `revenue_recovery.configure`/`revenue_recovery.manage` (RG-SOCLE-02, patron `Version20260824110000.php`
 * — Smart Flow I1).
 *
 * ⚠ Migration écrite à la main (DDL, même précaution que les autres modules de ce dépôt —
 * `doctrine:migrations:diff` reproposant systématiquement des suppressions d'index d'autres modules) :
 * un seul `CREATE TABLE` additif, aucune table existante touchée.
 *
 * `UNIQUE INDEX (establishment_id, trigger_type)` (RG-RR-01) : une séquence par établissement × type de
 * déclencheur.
 */
final class Version20260824220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Revenue Recovery (App\\RevenueRecovery, I1) : revenue_recovery_sequence + permissions revenue_recovery.read/configure/manage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE revenue_recovery_sequence ('
            . 'id BINARY(16) NOT NULL, '
            . 'establishment_id BINARY(16) NOT NULL, '
            . 'trigger_type VARCHAR(32) NOT NULL, '
            . 'active TINYINT(1) DEFAULT 0 NOT NULL, '
            . 'max_attempts SMALLINT DEFAULT 3 NOT NULL, '
            . 'steps JSON NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'updated_at DATETIME NOT NULL, '
            . 'UNIQUE INDEX uniq_recovery_sequence_establishment_trigger (establishment_id, trigger_type), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE revenue_recovery_sequence ADD CONSTRAINT FK_REVENUE_RECOVERY_SEQUENCE_ETAB FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');

        // Permissions revenue_recovery.* (RG-SOCLE-02) — idempotent, l'affectation aux rôles relève des
        // fixtures/M8. Les trois permissions du module sont seedées ensemble ici (I1 les couvre toutes,
        // contrairement à Smart Flow qui les avait échelonnées I1/I2).
        foreach (['read', 'configure', 'manage'] as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'revenue_recovery', $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'revenue_recovery'");
        $this->addSql('ALTER TABLE revenue_recovery_sequence DROP FOREIGN KEY FK_REVENUE_RECOVERY_SEQUENCE_ETAB');
        $this->addSql('DROP TABLE revenue_recovery_sequence');
    }
}
