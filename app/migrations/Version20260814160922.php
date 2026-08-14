<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260814160922 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_entree (id BINARY(16) NOT NULL, auteur VARCHAR(180) DEFAULT NULL, date_heure DATETIME NOT NULL, action VARCHAR(120) NOT NULL, cible_type VARCHAR(180) NOT NULL, cible_id VARCHAR(64) DEFAULT NULL, etablissement BINARY(16) DEFAULT NULL, INDEX idx_audit_date (date_heure), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE org_espace (id BINARY(16) NOT NULL, nom VARCHAR(180) NOT NULL, type VARCHAR(60) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_A5E944EAFF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE org_etablissement (id BINARY(16) NOT NULL, nom VARCHAR(180) NOT NULL, actif TINYINT NOT NULL, region_id BINARY(16) NOT NULL, INDEX IDX_A367292098260155 (region_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE org_groupe (id BINARY(16) NOT NULL, nom VARCHAR(180) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE org_region (id BINARY(16) NOT NULL, nom VARCHAR(180) NOT NULL, groupe_id BINARY(16) NOT NULL, INDEX IDX_AC20BCF17A45358C (groupe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sec_affectation (id BINARY(16) NOT NULL, utilisateur_id BINARY(16) NOT NULL, role_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_AA00F1A4FB88E14F (utilisateur_id), INDEX IDX_AA00F1A4D60322AC (role_id), INDEX IDX_AA00F1A4FF631228 (etablissement_id), UNIQUE INDEX uniq_affectation (utilisateur_id, role_id, etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sec_permission (id BINARY(16) NOT NULL, module VARCHAR(60) NOT NULL, action VARCHAR(60) NOT NULL, UNIQUE INDEX uniq_permission_module_action (module, action), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sec_role (id BINARY(16) NOT NULL, nom VARCHAR(120) NOT NULL, UNIQUE INDEX uniq_role_nom (nom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sec_role_permission (role_id BINARY(16) NOT NULL, permission_id BINARY(16) NOT NULL, INDEX IDX_DCC31FF2D60322AC (role_id), INDEX IDX_DCC31FF2FED90CCA (permission_id), PRIMARY KEY (role_id, permission_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sec_utilisateur (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, mot_de_passe VARCHAR(255) NOT NULL, nom VARCHAR(180) NOT NULL, actif TINYINT NOT NULL, roles_securite JSON NOT NULL, tentatives_echouees INT DEFAULT 0 NOT NULL, verrouille_jusqua DATETIME DEFAULT NULL, UNIQUE INDEX uniq_utilisateur_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE org_espace ADD CONSTRAINT FK_A5E944EAFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE org_etablissement ADD CONSTRAINT FK_A367292098260155 FOREIGN KEY (region_id) REFERENCES org_region (id)');
        $this->addSql('ALTER TABLE org_region ADD CONSTRAINT FK_AC20BCF17A45358C FOREIGN KEY (groupe_id) REFERENCES org_groupe (id)');
        $this->addSql('ALTER TABLE sec_affectation ADD CONSTRAINT FK_AA00F1A4FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE sec_affectation ADD CONSTRAINT FK_AA00F1A4D60322AC FOREIGN KEY (role_id) REFERENCES sec_role (id)');
        $this->addSql('ALTER TABLE sec_affectation ADD CONSTRAINT FK_AA00F1A4FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE sec_role_permission ADD CONSTRAINT FK_DCC31FF2D60322AC FOREIGN KEY (role_id) REFERENCES sec_role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sec_role_permission ADD CONSTRAINT FK_DCC31FF2FED90CCA FOREIGN KEY (permission_id) REFERENCES sec_permission (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE org_espace DROP FOREIGN KEY FK_A5E944EAFF631228');
        $this->addSql('ALTER TABLE org_etablissement DROP FOREIGN KEY FK_A367292098260155');
        $this->addSql('ALTER TABLE org_region DROP FOREIGN KEY FK_AC20BCF17A45358C');
        $this->addSql('ALTER TABLE sec_affectation DROP FOREIGN KEY FK_AA00F1A4FB88E14F');
        $this->addSql('ALTER TABLE sec_affectation DROP FOREIGN KEY FK_AA00F1A4D60322AC');
        $this->addSql('ALTER TABLE sec_affectation DROP FOREIGN KEY FK_AA00F1A4FF631228');
        $this->addSql('ALTER TABLE sec_role_permission DROP FOREIGN KEY FK_DCC31FF2D60322AC');
        $this->addSql('ALTER TABLE sec_role_permission DROP FOREIGN KEY FK_DCC31FF2FED90CCA');
        $this->addSql('DROP TABLE audit_entree');
        $this->addSql('DROP TABLE org_espace');
        $this->addSql('DROP TABLE org_etablissement');
        $this->addSql('DROP TABLE org_groupe');
        $this->addSql('DROP TABLE org_region');
        $this->addSql('DROP TABLE sec_affectation');
        $this->addSql('DROP TABLE sec_permission');
        $this->addSql('DROP TABLE sec_role');
        $this->addSql('DROP TABLE sec_role_permission');
        $this->addSql('DROP TABLE sec_utilisateur');
    }
}
