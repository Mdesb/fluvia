<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Smart Flow, incrément I2 (plan-smart-flow.md §1, note de conception) : `entitlement_id`
 * devient NULLABLE sur `smart_flow_reschedule_proposal` — une promotion de liste d'attente Smart Flow
 * (I2, `App\SmartFlow\Service\SlotWaitlistPromotionService`) matérialise une `RescheduleProposal` sans
 * crédit associé (`origin_reservation_ref`/`entitlement_id` tous deux nuls, seul
 * `source_waitlist_entry_ref` est renseigné), contrairement à une proposition I1 issue d'un no-show
 * restitué-avec-crédit. Même correction de schéma que celle déjà appliquée à `origin_reservation_ref`
 * par `Version20260824110000.php`.
 *
 * ⚠ Migration écrite à la main (DDL, même précaution que les autres modules de ce dépôt —
 * `doctrine:migrations:diff` reproposant systématiquement des suppressions d'index d'autres modules) :
 * un seul `ALTER TABLE MODIFY` additif (assouplissement de contrainte), aucune donnée existante
 * affectée.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Smart Flow (I2) : entitlement_id nullable sur smart_flow_reschedule_proposal (promotion liste d\'attente sans crédit).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE smart_flow_reschedule_proposal MODIFY entitlement_id BINARY(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE smart_flow_reschedule_proposal MODIFY entitlement_id BINARY(16) NOT NULL');
    }
}
