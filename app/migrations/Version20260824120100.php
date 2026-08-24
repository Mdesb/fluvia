<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module Smart Flow (App\SmartFlow, incrément I2 — créneaux libérés + liste d'attente, plan-smart-flow.md
 * §4) : table `smart_flow_slot_waitlist_entry` + seed de la permission `smart_flow.read` (RG-SOCLE-02).
 * `smart_flow.reschedule_manage`, déjà seedée par I1 (`Version20260824110000.php`), couvre
 * `POST /smart-flow/waitlist-entries` (§3 du plan : mapping retenu, action opérationnelle d'agent).
 *
 * ⚠ Migration écrite à la main (DDL, même précaution que les autres modules de ce dépôt) : un seul
 * `CREATE TABLE` additif, aucune table existante touchée.
 */
final class Version20260824120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Smart Flow (App\\SmartFlow, I2) : smart_flow_slot_waitlist_entry + permission smart_flow.read.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE smart_flow_slot_waitlist_entry ('
            . 'id BINARY(16) NOT NULL, '
            . 'establishment_id BINARY(16) NOT NULL, '
            . 'resource_id BINARY(16) NOT NULL, '
            . 'beneficiary_id BINARY(16) NOT NULL, '
            . 'search_window_start DATETIME NOT NULL, '
            . 'search_window_end DATETIME NOT NULL, '
            . 'rank SMALLINT NOT NULL, '
            . "status VARCHAR(10) DEFAULT 'waiting' NOT NULL, "
            . 'promoted_proposal_ref BINARY(16) DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'INDEX idx_slot_waitlist_resource_status (resource_id, status), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE smart_flow_slot_waitlist_entry ADD CONSTRAINT FK_SMART_FLOW_SLOT_WAITLIST_ENTRY_ETAB FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');

        $this->addSql(
            'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
            [Uuid::v4()->toBinary(), 'smart_flow', 'read'],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'smart_flow' AND action = 'read'");
        $this->addSql('ALTER TABLE smart_flow_slot_waitlist_entry DROP FOREIGN KEY FK_SMART_FLOW_SLOT_WAITLIST_ENTRY_ETAB');
        $this->addSql('DROP TABLE smart_flow_slot_waitlist_entry');
    }
}
