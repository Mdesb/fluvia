<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module Smart Flow (`App\SmartFlow`, incrément I1 — report de no-show, SF-2, plan-smart-flow.md §4) :
 * table `smart_flow_reschedule_proposal` + seed des permissions `smart_flow.reschedule_manage`/
 * `smart_flow.reschedule_read_own` (RG-SOCLE-02, patron `Version20260822090000.php` — DMS-1).
 *
 * ⚠ Migration écrite à la main (DDL, même précaution que les autres modules de ce dépôt —
 * `doctrine:migrations:diff` reproposant systématiquement des suppressions d'index d'autres modules) :
 * un seul `CREATE TABLE` additif, aucune table existante touchée.
 *
 * `origin_reservation_ref` **NULLABLE** avec index **unique** (§0.4/§1 du plan — NULL n'entre pas en
 * conflit sous MariaDB, plusieurs lignes NULL autorisées) : une future promotion de liste d'attente
 * Smart Flow (I2) réutilisera cette même table (colonne `source_waitlist_entry_ref`, déjà posée ici)
 * sans `origin_reservation_ref`.
 */
final class Version20260824110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Smart Flow (App\\SmartFlow, I1) : smart_flow_reschedule_proposal + permissions smart_flow.reschedule_manage/reschedule_read_own.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE smart_flow_reschedule_proposal ('
            . 'id BINARY(16) NOT NULL, '
            . 'establishment_id BINARY(16) NOT NULL, '
            . 'origin_reservation_ref BINARY(16) DEFAULT NULL, '
            . 'source_waitlist_entry_ref BINARY(16) DEFAULT NULL, '
            . 'origin_slot_id BINARY(16) NOT NULL, '
            . 'customer_id BINARY(16) NOT NULL, '
            . 'entitlement_id BINARY(16) NOT NULL, '
            . "status VARCHAR(10) DEFAULT 'searching' NOT NULL, "
            . 'proposed_slot_id BINARY(16) DEFAULT NULL, '
            . 'expires_at DATETIME NOT NULL, '
            . 'confirmed_reservation_ref BINARY(16) DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'last_search_attempt_at DATETIME DEFAULT NULL, '
            . 'UNIQUE INDEX uniq_reschedule_proposal_origin_reservation (origin_reservation_ref), '
            . 'INDEX idx_reschedule_proposal_establishment_status (establishment_id, status), '
            . 'INDEX idx_reschedule_proposal_origin_slot (origin_slot_id), '
            . 'INDEX idx_reschedule_proposal_customer (customer_id), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE smart_flow_reschedule_proposal ADD CONSTRAINT FK_SMART_FLOW_RESCHEDULE_PROPOSAL_ETAB FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');

        // Permissions smart_flow.* (RG-SOCLE-02) — idempotent, l'affectation aux rôles relève des
        // fixtures/M8. Seules les deux permissions réellement câblées en I1 (§0.11 du plan) : `read`/
        // `manage` (paramétrage) suivront avec I2/I3.
        foreach (['reschedule_manage', 'reschedule_read_own'] as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'smart_flow', $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'smart_flow'");
        $this->addSql('ALTER TABLE smart_flow_reschedule_proposal DROP FOREIGN KEY FK_SMART_FLOW_RESCHEDULE_PROPOSAL_ETAB');
        $this->addSql('DROP TABLE smart_flow_reschedule_proposal');
    }
}
