<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Smart Flow (App\SmartFlow, incrément I2, plan-smart-flow.md §4) : table technique
 * `smart_flow_slot_release_trace` — support de l'idempotence de `slot.released` (RG-SF-04, §0.4 du
 * plan) : contrainte `UNIQUE (slot_id, trigger_subject_id)`.
 *
 * ⚠ Migration écrite à la main (DDL), un seul `CREATE TABLE` additif, aucune table existante touchée.
 */
final class Version20260824210200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Smart Flow (App\\SmartFlow, I2) : smart_flow_slot_release_trace (idempotence slot.released, RG-SF-04).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE smart_flow_slot_release_trace ('
            . 'id BINARY(16) NOT NULL, '
            . 'establishment_id BINARY(16) NOT NULL, '
            . 'slot_id BINARY(16) NOT NULL, '
            . 'resource_id BINARY(16) NOT NULL, '
            . 'trigger_event_name VARCHAR(32) NOT NULL, '
            . 'trigger_subject_id BINARY(16) NOT NULL, '
            . 'released TINYINT(1) DEFAULT 0 NOT NULL, '
            . 'processed_at DATETIME NOT NULL, '
            . 'UNIQUE INDEX uniq_slot_release_trace_slot_trigger (slot_id, trigger_subject_id), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE smart_flow_slot_release_trace ADD CONSTRAINT FK_SMART_FLOW_SLOT_RELEASE_TRACE_ETAB FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE smart_flow_slot_release_trace DROP FOREIGN KEY FK_SMART_FLOW_SLOT_RELEASE_TRACE_ETAB');
        $this->addSql('DROP TABLE smart_flow_slot_release_trace');
    }
}
