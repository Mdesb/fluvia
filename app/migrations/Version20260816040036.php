<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260816040036 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verticale Patinoire (App\\Patinoire) : ParcPatins par pointure, LocationPatins/CautionLocationPatins, GrilleRetenue/RetenueCaution, ListeAttentePointure, Affutage (prestation_client/maintenance_parc), ZonePatinoire (overlay EspaceAcces), SaisonEphemere.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE patin_affutage (id BINARY(16) NOT NULL, type VARCHAR(18) NOT NULL, date_entree_atelier DATETIME NOT NULL, date_sortie_atelier DATETIME DEFAULT NULL, statut VARCHAR(10) DEFAULT \'en_attente\' NOT NULL, ligne_vente_id BINARY(16) DEFAULT NULL, parc_patins_id BINARY(16) DEFAULT NULL, technicien_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_2735C562225D063C (ligne_vente_id), INDEX IDX_2735C562BC5FE8E8 (parc_patins_id), INDEX IDX_2735C56213457256 (technicien_id), INDEX IDX_2735C562FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE patin_caution_location (id BINARY(16) NOT NULL, location_active BINARY(16) DEFAULT NULL, montant NUMERIC(6, 2) NOT NULL, statut VARCHAR(17) DEFAULT \'encaissee\' NOT NULL, moyen_encaissement VARCHAR(30) DEFAULT NULL, regie_mouvement_ref BINARY(16) DEFAULT NULL, date_encaissement DATETIME DEFAULT NULL, date_liberation DATETIME DEFAULT NULL, location_id BINARY(16) NOT NULL, INDEX IDX_290CB7F264D218E (location_id), UNIQUE INDEX uniq_caution_location_active (location_active), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE patin_grille_retenue (id BINARY(16) NOT NULL, motif VARCHAR(22) NOT NULL, mode VARCHAR(20) NOT NULL, montant_ou_taux NUMERIC(6, 2) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, etablissement_id BINARY(16) NOT NULL, parc_patins_id BINARY(16) DEFAULT NULL, INDEX IDX_EC21896BFF631228 (etablissement_id), INDEX IDX_EC21896BBC5FE8E8 (parc_patins_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE patin_liste_attente_pointure (id BINARY(16) NOT NULL, rang SMALLINT NOT NULL, date_demande DATETIME NOT NULL, statut VARCHAR(10) DEFAULT \'en_attente\' NOT NULL, pointure_voisine_proposee SMALLINT DEFAULT NULL, date_expiration_proposition DATETIME DEFAULT NULL, parc_patins_id BINARY(16) NOT NULL, beneficiaire_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_91F07C9DBC5FE8E8 (parc_patins_id), INDEX IDX_91F07C9D5AF81F68 (beneficiaire_id), INDEX IDX_91F07C9DFF631228 (etablissement_id), UNIQUE INDEX uniq_liste_attente_parc_rang (parc_patins_id, rang), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE patin_location (id BINARY(16) NOT NULL, date_sortie DATETIME NOT NULL, date_retour DATETIME DEFAULT NULL, etat_retour VARCHAR(10) DEFAULT NULL, statut VARCHAR(12) DEFAULT \'en_cours\' NOT NULL, parc_patins_id BINARY(16) NOT NULL, ligne_vente_id BINARY(16) DEFAULT NULL, beneficiaire_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_BD1D37A9BC5FE8E8 (parc_patins_id), INDEX IDX_BD1D37A9225D063C (ligne_vente_id), INDEX IDX_BD1D37A95AF81F68 (beneficiaire_id), INDEX IDX_BD1D37A9FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE patin_parc_patins (id BINARY(16) NOT NULL, pointure SMALLINT NOT NULL, produit_location_ref BINARY(16) DEFAULT NULL, quantite_totale SMALLINT NOT NULL, quantite_sortie SMALLINT DEFAULT 0 NOT NULL, quantite_en_affutage SMALLINT DEFAULT 0 NOT NULL, quantite_hs SMALLINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_85D1E5F2FF631228 (etablissement_id), UNIQUE INDEX uniq_parc_patins_etab_pointure (etablissement_id, pointure), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE patin_retenue_caution (id BINARY(16) NOT NULL, montant_retenu NUMERIC(6, 2) NOT NULL, mouvement_regie_ref BINARY(16) DEFAULT NULL, motif VARCHAR(255) NOT NULL, horodatage DATETIME NOT NULL, forcee TINYINT DEFAULT 0 NOT NULL, location_id BINARY(16) NOT NULL, grille_appliquee_id BINARY(16) DEFAULT NULL, agent_id BINARY(16) DEFAULT NULL, INDEX IDX_9E6E40C181CCDFA7 (grille_appliquee_id), INDEX IDX_9E6E40C13414710B (agent_id), UNIQUE INDEX uniq_retenue_caution_location (location_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE patin_saison_ephemere (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, date_ouverture DATE NOT NULL, date_fermeture DATE NOT NULL, fenetre_vente_debut DATE NOT NULL, fenetre_vente_fin DATE NOT NULL, catalogue_associe JSON DEFAULT NULL, bascule VARCHAR(12) DEFAULT \'automatique\' NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, etablissement_id BINARY(16) NOT NULL, saison_m1_id BINARY(16) DEFAULT NULL, INDEX IDX_C2892E50FF631228 (etablissement_id), INDEX IDX_C2892E50C22803AE (saison_m1_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE patin_zone (id BINARY(16) NOT NULL, type_zone VARCHAR(8) NOT NULL, espace_acces_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_61FDC77EFF631228 (etablissement_id), UNIQUE INDEX uniq_zone_patinoire_espace_acces (espace_acces_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE patin_affutage ADD CONSTRAINT FK_2735C562225D063C FOREIGN KEY (ligne_vente_id) REFERENCES vente_ligne (id)');
        $this->addSql('ALTER TABLE patin_affutage ADD CONSTRAINT FK_2735C562BC5FE8E8 FOREIGN KEY (parc_patins_id) REFERENCES patin_parc_patins (id)');
        $this->addSql('ALTER TABLE patin_affutage ADD CONSTRAINT FK_2735C56213457256 FOREIGN KEY (technicien_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE patin_affutage ADD CONSTRAINT FK_2735C562FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE patin_caution_location ADD CONSTRAINT FK_290CB7F264D218E FOREIGN KEY (location_id) REFERENCES patin_location (id)');
        $this->addSql('ALTER TABLE patin_grille_retenue ADD CONSTRAINT FK_EC21896BFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE patin_grille_retenue ADD CONSTRAINT FK_EC21896BBC5FE8E8 FOREIGN KEY (parc_patins_id) REFERENCES patin_parc_patins (id)');
        $this->addSql('ALTER TABLE patin_liste_attente_pointure ADD CONSTRAINT FK_91F07C9DBC5FE8E8 FOREIGN KEY (parc_patins_id) REFERENCES patin_parc_patins (id)');
        $this->addSql('ALTER TABLE patin_liste_attente_pointure ADD CONSTRAINT FK_91F07C9D5AF81F68 FOREIGN KEY (beneficiaire_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE patin_liste_attente_pointure ADD CONSTRAINT FK_91F07C9DFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE patin_location ADD CONSTRAINT FK_BD1D37A9BC5FE8E8 FOREIGN KEY (parc_patins_id) REFERENCES patin_parc_patins (id)');
        $this->addSql('ALTER TABLE patin_location ADD CONSTRAINT FK_BD1D37A9225D063C FOREIGN KEY (ligne_vente_id) REFERENCES vente_ligne (id)');
        $this->addSql('ALTER TABLE patin_location ADD CONSTRAINT FK_BD1D37A95AF81F68 FOREIGN KEY (beneficiaire_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE patin_location ADD CONSTRAINT FK_BD1D37A9FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE patin_parc_patins ADD CONSTRAINT FK_85D1E5F2FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE patin_retenue_caution ADD CONSTRAINT FK_9E6E40C164D218E FOREIGN KEY (location_id) REFERENCES patin_location (id)');
        $this->addSql('ALTER TABLE patin_retenue_caution ADD CONSTRAINT FK_9E6E40C181CCDFA7 FOREIGN KEY (grille_appliquee_id) REFERENCES patin_grille_retenue (id)');
        $this->addSql('ALTER TABLE patin_retenue_caution ADD CONSTRAINT FK_9E6E40C13414710B FOREIGN KEY (agent_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE patin_saison_ephemere ADD CONSTRAINT FK_C2892E50FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE patin_saison_ephemere ADD CONSTRAINT FK_C2892E50C22803AE FOREIGN KEY (saison_m1_id) REFERENCES off_saison (id)');
        $this->addSql('ALTER TABLE patin_zone ADD CONSTRAINT FK_61FDC77EF353E39C FOREIGN KEY (espace_acces_id) REFERENCES acces_espace_acces (id)');
        $this->addSql('ALTER TABLE patin_zone ADD CONSTRAINT FK_61FDC77EFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE patin_affutage DROP FOREIGN KEY FK_2735C562225D063C');
        $this->addSql('ALTER TABLE patin_affutage DROP FOREIGN KEY FK_2735C562BC5FE8E8');
        $this->addSql('ALTER TABLE patin_affutage DROP FOREIGN KEY FK_2735C56213457256');
        $this->addSql('ALTER TABLE patin_affutage DROP FOREIGN KEY FK_2735C562FF631228');
        $this->addSql('ALTER TABLE patin_caution_location DROP FOREIGN KEY FK_290CB7F264D218E');
        $this->addSql('ALTER TABLE patin_grille_retenue DROP FOREIGN KEY FK_EC21896BFF631228');
        $this->addSql('ALTER TABLE patin_grille_retenue DROP FOREIGN KEY FK_EC21896BBC5FE8E8');
        $this->addSql('ALTER TABLE patin_liste_attente_pointure DROP FOREIGN KEY FK_91F07C9DBC5FE8E8');
        $this->addSql('ALTER TABLE patin_liste_attente_pointure DROP FOREIGN KEY FK_91F07C9D5AF81F68');
        $this->addSql('ALTER TABLE patin_liste_attente_pointure DROP FOREIGN KEY FK_91F07C9DFF631228');
        $this->addSql('ALTER TABLE patin_location DROP FOREIGN KEY FK_BD1D37A9BC5FE8E8');
        $this->addSql('ALTER TABLE patin_location DROP FOREIGN KEY FK_BD1D37A9225D063C');
        $this->addSql('ALTER TABLE patin_location DROP FOREIGN KEY FK_BD1D37A95AF81F68');
        $this->addSql('ALTER TABLE patin_location DROP FOREIGN KEY FK_BD1D37A9FF631228');
        $this->addSql('ALTER TABLE patin_parc_patins DROP FOREIGN KEY FK_85D1E5F2FF631228');
        $this->addSql('ALTER TABLE patin_retenue_caution DROP FOREIGN KEY FK_9E6E40C164D218E');
        $this->addSql('ALTER TABLE patin_retenue_caution DROP FOREIGN KEY FK_9E6E40C181CCDFA7');
        $this->addSql('ALTER TABLE patin_retenue_caution DROP FOREIGN KEY FK_9E6E40C13414710B');
        $this->addSql('ALTER TABLE patin_saison_ephemere DROP FOREIGN KEY FK_C2892E50FF631228');
        $this->addSql('ALTER TABLE patin_saison_ephemere DROP FOREIGN KEY FK_C2892E50C22803AE');
        $this->addSql('ALTER TABLE patin_zone DROP FOREIGN KEY FK_61FDC77EF353E39C');
        $this->addSql('ALTER TABLE patin_zone DROP FOREIGN KEY FK_61FDC77EFF631228');
        $this->addSql('DROP TABLE patin_affutage');
        $this->addSql('DROP TABLE patin_caution_location');
        $this->addSql('DROP TABLE patin_grille_retenue');
        $this->addSql('DROP TABLE patin_liste_attente_pointure');
        $this->addSql('DROP TABLE patin_location');
        $this->addSql('DROP TABLE patin_parc_patins');
        $this->addSql('DROP TABLE patin_retenue_caution');
        $this->addSql('DROP TABLE patin_saison_ephemere');
        $this->addSql('DROP TABLE patin_zone');
    }
}
