<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module transverse « groupes de participants » (`App\Group`) : trois tables neuves —
 * `group_participant_group` (le groupe réutilisable), `group_participant` (sa liste nominative) et
 * `group_booking` (sa réservation, généralisation du dossier musée).
 *
 * ⚠ Rien à voir avec `App\Organisation\Entity\Groupe` (le locataire) : ces tables sont préfixées
 * `group_` et portent le cloisonnement par `etablissement` (RG-SOCLE-05).
 *
 * Écrite à la main d'après le `SHOW CREATE TABLE` du schéma monté par le mapping (noms d'index et de
 * clés étrangères de Doctrine conservés).
 */
final class Version20260908093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Group : group_participant_group, group_participant, group_booking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE group_participant_group (id BINARY(16) NOT NULL, label VARCHAR(160) NOT NULL, type VARCHAR(20) DEFAULT 'other' NOT NULL, organizer_name VARCHAR(160) NOT NULL, organizer_email VARCHAR(180) DEFAULT NULL, organizer_phone VARCHAR(40) DEFAULT NULL, headcount INT DEFAULT 0 NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, etablissement_id BINARY(16) NOT NULL, client_id BINARY(16) DEFAULT NULL, INDEX IDX_7231C240FF631228 (etablissement_id), INDEX IDX_7231C24019EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE group_participant (id BINARY(16) NOT NULL, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, category VARCHAR(12) DEFAULT 'adult' NOT NULL, group_id BINARY(16) NOT NULL, beneficiaire_id BINARY(16) DEFAULT NULL, INDEX IDX_F22774D0FE54D947 (group_id), INDEX IDX_F22774D05AF81F68 (beneficiaire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE group_booking (id BINARY(16) NOT NULL, effectif INT DEFAULT 0 NOT NULL, accompagnateurs INT DEFAULT 0 NOT NULL, status VARCHAR(12) DEFAULT 'option' NOT NULL, payment_status VARCHAR(16) DEFAULT 'pending' NOT NULL, option_expires_at DATE DEFAULT NULL, created_at DATETIME NOT NULL, etablissement_id BINARY(16) NOT NULL, group_id BINARY(16) NOT NULL, creneau_id BINARY(16) DEFAULT NULL, activite_id BINARY(16) DEFAULT NULL, payer_id BINARY(16) DEFAULT NULL, vente_rattachee_id BINARY(16) DEFAULT NULL, INDEX IDX_660CB9D2FF631228 (etablissement_id), INDEX IDX_660CB9D2FE54D947 (group_id), INDEX IDX_660CB9D27D0729A9 (creneau_id), INDEX IDX_660CB9D29B0F88B1 (activite_id), INDEX IDX_660CB9D2C17AD9A9 (payer_id), INDEX IDX_660CB9D21977E20D (vente_rattachee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql('ALTER TABLE group_participant_group ADD CONSTRAINT FK_7231C240FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE group_participant_group ADD CONSTRAINT FK_7231C24019EB6921 FOREIGN KEY (client_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE group_participant ADD CONSTRAINT FK_F22774D0FE54D947 FOREIGN KEY (group_id) REFERENCES group_participant_group (id)');
        $this->addSql('ALTER TABLE group_participant ADD CONSTRAINT FK_F22774D05AF81F68 FOREIGN KEY (beneficiaire_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE group_booking ADD CONSTRAINT FK_660CB9D2FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE group_booking ADD CONSTRAINT FK_660CB9D2FE54D947 FOREIGN KEY (group_id) REFERENCES group_participant_group (id)');
        $this->addSql('ALTER TABLE group_booking ADD CONSTRAINT FK_660CB9D27D0729A9 FOREIGN KEY (creneau_id) REFERENCES reservation_creneau (id)');
        $this->addSql('ALTER TABLE group_booking ADD CONSTRAINT FK_660CB9D29B0F88B1 FOREIGN KEY (activite_id) REFERENCES reservation_activite (id)');
        $this->addSql('ALTER TABLE group_booking ADD CONSTRAINT FK_660CB9D2C17AD9A9 FOREIGN KEY (payer_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE group_booking ADD CONSTRAINT FK_660CB9D21977E20D FOREIGN KEY (vente_rattachee_id) REFERENCES vente_vente (id)');
    }

    public function down(Schema $schema): void
    {
        // Enfants d'abord : `group_participant` et `group_booking` référencent `group_participant_group`.
        $this->addSql('ALTER TABLE group_participant DROP FOREIGN KEY FK_F22774D0FE54D947');
        $this->addSql('ALTER TABLE group_booking DROP FOREIGN KEY FK_660CB9D2FE54D947');
        $this->addSql('DROP TABLE group_booking');
        $this->addSql('DROP TABLE group_participant');
        $this->addSql('DROP TABLE group_participant_group');
    }
}
