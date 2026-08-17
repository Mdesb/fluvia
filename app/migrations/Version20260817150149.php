<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Personnel & planning d'équipe (plan-personnel.md §5.1) — migration structurelle : schéma
 * `personnel_employe`, `personnel_rattachement`, `personnel_qualification`,
 * `personnel_creneau_travail`, `personnel_affectation_travail`, `personnel_absence`,
 * `personnel_badge_staff`, `personnel_portee_acces` (+ table de jointure
 * `personnel_portee_acces_espace` vers `acces_espace_acces`). FKs sortantes vers `sec_utilisateur`,
 * `org_etablissement`/`org_espace`, `acces_support`, `acces_droit_acces`, `acces_espace_acces`
 * (Personnel dépend de Securite/Organisation/Acces, sens conforme constitution). Générée par
 * `doctrine:migrations:diff` à partir des entités `App\Personnel\Entity\*`. Aucune table/colonne
 * `App\Acces`/`App\Piscine`/`App\Padel` modifiée.
 */
final class Version20260817150149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Personnel (plan-personnel.md) : schéma personnel_employe/rattachement/qualification/creneau_travail/affectation_travail/absence/badge_staff/portee_acces.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE personnel_absence (id BINARY(16) NOT NULL, debut DATETIME NOT NULL, fin DATETIME NOT NULL, type VARCHAR(10) NOT NULL, statut VARCHAR(10) DEFAULT \'declaree\' NOT NULL, motif VARCHAR(255) DEFAULT NULL, employe_id BINARY(16) NOT NULL, validee_par_id BINARY(16) DEFAULT NULL, INDEX IDX_D67CFCE61B65292 (employe_id), INDEX IDX_D67CFCE6629A7BB2 (validee_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE personnel_affectation_travail (id BINARY(16) NOT NULL, statut VARCHAR(20) DEFAULT \'planifiee\' NOT NULL, creneau_travail_id BINARY(16) NOT NULL, employe_id BINARY(16) NOT NULL, qualification_utilisee_id BINARY(16) DEFAULT NULL, INDEX IDX_3A28B7FAB74BA28A (creneau_travail_id), INDEX IDX_3A28B7FA1B65292 (employe_id), INDEX IDX_3A28B7FA5AD35FDE (qualification_utilisee_id), UNIQUE INDEX uniq_affectation_creneau_employe (creneau_travail_id, employe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE personnel_badge_staff (id BINARY(16) NOT NULL, statut VARCHAR(10) DEFAULT \'actif\' NOT NULL, date_emission DATETIME NOT NULL, date_revocation DATETIME DEFAULT NULL, motif_revocation VARCHAR(255) DEFAULT NULL, cle_active VARCHAR(80) DEFAULT NULL, employe_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, support_id BINARY(16) NOT NULL, droit_acces_id BINARY(16) NOT NULL, INDEX IDX_DEC4900E1B65292 (employe_id), INDEX IDX_DEC4900EFF631228 (etablissement_id), INDEX IDX_DEC4900E315B405 (support_id), INDEX IDX_DEC4900E2453207F (droit_acces_id), UNIQUE INDEX uniq_badge_cle_active (cle_active), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE personnel_creneau_travail (id BINARY(16) NOT NULL, creneau_reservation_ref BINARY(16) DEFAULT NULL, libelle_poste VARCHAR(120) NOT NULL, debut DATETIME NOT NULL, fin DATETIME NOT NULL, qualification_requise VARCHAR(10) DEFAULT NULL, effectif_requis SMALLINT DEFAULT 1 NOT NULL, statut VARCHAR(10) DEFAULT \'planifie\' NOT NULL, motif_recurrence VARCHAR(16) DEFAULT NULL, fin_recurrence DATE DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, espace_id BINARY(16) DEFAULT NULL, INDEX IDX_58A5E532FF631228 (etablissement_id), INDEX IDX_58A5E532B6885C6C (espace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE personnel_employe (id BINARY(16) NOT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, matricule VARCHAR(40) DEFAULT NULL, poste VARCHAR(80) NOT NULL, type_contrat VARCHAR(16) NOT NULL, date_entree DATE NOT NULL, date_sortie DATE DEFAULT NULL, statut VARCHAR(10) DEFAULT \'actif\' NOT NULL, utilisateur_id BINARY(16) DEFAULT NULL, INDEX IDX_5822CF96FB88E14F (utilisateur_id), UNIQUE INDEX uniq_employe_matricule (matricule), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE personnel_portee_acces (id BINARY(16) NOT NULL, mode_horaire VARCHAR(18) NOT NULL, marge_avant_apres SMALLINT DEFAULT NULL, badge_staff_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_portee_badge_staff (badge_staff_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE personnel_portee_acces_espace (portee_acces_employe_id BINARY(16) NOT NULL, espace_acces_id BINARY(16) NOT NULL, INDEX IDX_21F0B2F8961461 (portee_acces_employe_id), INDEX IDX_21F0B2F8F353E39C (espace_acces_id), PRIMARY KEY (portee_acces_employe_id, espace_acces_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE personnel_qualification (id BINARY(16) NOT NULL, type VARCHAR(10) NOT NULL, libelle VARCHAR(120) DEFAULT NULL, date_obtention DATE DEFAULT NULL, date_validite DATE NOT NULL, employe_id BINARY(16) NOT NULL, INDEX IDX_B8D9902C1B65292 (employe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE personnel_rattachement (id BINARY(16) NOT NULL, poste_local VARCHAR(80) DEFAULT NULL, debut DATE NOT NULL, fin DATE DEFAULT NULL, employe_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_83A6A7C01B65292 (employe_id), INDEX IDX_83A6A7C0FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE personnel_absence ADD CONSTRAINT FK_D67CFCE61B65292 FOREIGN KEY (employe_id) REFERENCES personnel_employe (id)');
        $this->addSql('ALTER TABLE personnel_absence ADD CONSTRAINT FK_D67CFCE6629A7BB2 FOREIGN KEY (validee_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE personnel_affectation_travail ADD CONSTRAINT FK_3A28B7FAB74BA28A FOREIGN KEY (creneau_travail_id) REFERENCES personnel_creneau_travail (id)');
        $this->addSql('ALTER TABLE personnel_affectation_travail ADD CONSTRAINT FK_3A28B7FA1B65292 FOREIGN KEY (employe_id) REFERENCES personnel_employe (id)');
        $this->addSql('ALTER TABLE personnel_affectation_travail ADD CONSTRAINT FK_3A28B7FA5AD35FDE FOREIGN KEY (qualification_utilisee_id) REFERENCES personnel_qualification (id)');
        $this->addSql('ALTER TABLE personnel_badge_staff ADD CONSTRAINT FK_DEC4900E1B65292 FOREIGN KEY (employe_id) REFERENCES personnel_employe (id)');
        $this->addSql('ALTER TABLE personnel_badge_staff ADD CONSTRAINT FK_DEC4900EFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE personnel_badge_staff ADD CONSTRAINT FK_DEC4900E315B405 FOREIGN KEY (support_id) REFERENCES acces_support (id)');
        $this->addSql('ALTER TABLE personnel_badge_staff ADD CONSTRAINT FK_DEC4900E2453207F FOREIGN KEY (droit_acces_id) REFERENCES acces_droit_acces (id)');
        $this->addSql('ALTER TABLE personnel_creneau_travail ADD CONSTRAINT FK_58A5E532FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE personnel_creneau_travail ADD CONSTRAINT FK_58A5E532B6885C6C FOREIGN KEY (espace_id) REFERENCES org_espace (id)');
        $this->addSql('ALTER TABLE personnel_employe ADD CONSTRAINT FK_5822CF96FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE personnel_portee_acces ADD CONSTRAINT FK_A2528FE2CF9C2C63 FOREIGN KEY (badge_staff_id) REFERENCES personnel_badge_staff (id)');
        $this->addSql('ALTER TABLE personnel_portee_acces_espace ADD CONSTRAINT FK_21F0B2F8961461 FOREIGN KEY (portee_acces_employe_id) REFERENCES personnel_portee_acces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE personnel_portee_acces_espace ADD CONSTRAINT FK_21F0B2F8F353E39C FOREIGN KEY (espace_acces_id) REFERENCES acces_espace_acces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE personnel_qualification ADD CONSTRAINT FK_B8D9902C1B65292 FOREIGN KEY (employe_id) REFERENCES personnel_employe (id)');
        $this->addSql('ALTER TABLE personnel_rattachement ADD CONSTRAINT FK_83A6A7C01B65292 FOREIGN KEY (employe_id) REFERENCES personnel_employe (id)');
        $this->addSql('ALTER TABLE personnel_rattachement ADD CONSTRAINT FK_83A6A7C0FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE personnel_absence DROP FOREIGN KEY FK_D67CFCE61B65292');
        $this->addSql('ALTER TABLE personnel_absence DROP FOREIGN KEY FK_D67CFCE6629A7BB2');
        $this->addSql('ALTER TABLE personnel_affectation_travail DROP FOREIGN KEY FK_3A28B7FAB74BA28A');
        $this->addSql('ALTER TABLE personnel_affectation_travail DROP FOREIGN KEY FK_3A28B7FA1B65292');
        $this->addSql('ALTER TABLE personnel_affectation_travail DROP FOREIGN KEY FK_3A28B7FA5AD35FDE');
        $this->addSql('ALTER TABLE personnel_badge_staff DROP FOREIGN KEY FK_DEC4900E1B65292');
        $this->addSql('ALTER TABLE personnel_badge_staff DROP FOREIGN KEY FK_DEC4900EFF631228');
        $this->addSql('ALTER TABLE personnel_badge_staff DROP FOREIGN KEY FK_DEC4900E315B405');
        $this->addSql('ALTER TABLE personnel_badge_staff DROP FOREIGN KEY FK_DEC4900E2453207F');
        $this->addSql('ALTER TABLE personnel_creneau_travail DROP FOREIGN KEY FK_58A5E532FF631228');
        $this->addSql('ALTER TABLE personnel_creneau_travail DROP FOREIGN KEY FK_58A5E532B6885C6C');
        $this->addSql('ALTER TABLE personnel_employe DROP FOREIGN KEY FK_5822CF96FB88E14F');
        $this->addSql('ALTER TABLE personnel_portee_acces DROP FOREIGN KEY FK_A2528FE2CF9C2C63');
        $this->addSql('ALTER TABLE personnel_portee_acces_espace DROP FOREIGN KEY FK_21F0B2F8961461');
        $this->addSql('ALTER TABLE personnel_portee_acces_espace DROP FOREIGN KEY FK_21F0B2F8F353E39C');
        $this->addSql('ALTER TABLE personnel_qualification DROP FOREIGN KEY FK_B8D9902C1B65292');
        $this->addSql('ALTER TABLE personnel_rattachement DROP FOREIGN KEY FK_83A6A7C01B65292');
        $this->addSql('ALTER TABLE personnel_rattachement DROP FOREIGN KEY FK_83A6A7C0FF631228');
        $this->addSql('DROP TABLE personnel_absence');
        $this->addSql('DROP TABLE personnel_affectation_travail');
        $this->addSql('DROP TABLE personnel_badge_staff');
        $this->addSql('DROP TABLE personnel_creneau_travail');
        $this->addSql('DROP TABLE personnel_employe');
        $this->addSql('DROP TABLE personnel_portee_acces');
        $this->addSql('DROP TABLE personnel_portee_acces_espace');
        $this->addSql('DROP TABLE personnel_qualification');
        $this->addSql('DROP TABLE personnel_rattachement');
    }
}
