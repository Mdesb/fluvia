<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L6 Verticale Piscine — migration structurelle : tables piscine_* (POSS, bassin, ligne d'eau,
 * créneau bassin/public + jointure lignes, jauge grand public calculée, qualification/affectation
 * encadrant, casier/caution/relance/forçage, bracelet étanche, paramètres établissement).
 * Contraintes : `OneToOne` unique `Poss.espaceAcces` (délégation seuil/mode L3, pas de duplication) ;
 * unique `(bassin, numero)` sur `LigneEau` ; unique `(etablissement, zone, numero)` sur `Casier` ; un
 * seul `CautionCasier` actif par casier (colonne générée `casier_actif` + index unique partiel,
 * pattern `Appairage.support_actif` de L3) ; `OneToOne` uniques
 * `JaugeGrandPublicCalculee.creneauBassin`, `BraceletEtanche.support`,
 * `ParametrePiscineEtablissement.etablissement`. FK vers `org_etablissement`/`org_espace`/
 * `sec_utilisateur` (socle) et vers `acces_espace_acces`/`acces_support` (L3, lus non modifiés) —
 * suppose les migrations socle L0 + M1 + L3 jouées d'abord.
 */
final class Version20260815060805 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'L6 Piscine : schéma piscine_* (POSS, bassin/lignes, créneaux, casiers/caution, bracelet) + index/contraintes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE piscine_affectation_encadrant (id BINARY(16) NOT NULL, creneau_bassin_id BINARY(16) NOT NULL, qualification_id BINARY(16) NOT NULL, INDEX IDX_D11DF28B8D11522 (creneau_bassin_id), INDEX IDX_D11DF28B1A75EE38 (qualification_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_bassin (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, nb_lignes SMALLINT NOT NULL, capacite INT NOT NULL, occupation_courante INT DEFAULT 0 NOT NULL, espace_id BINARY(16) NOT NULL, espace_acces_dedie_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_CF34E62B6885C6C (espace_id), UNIQUE INDEX UNIQ_CF34E6275E5FA57 (espace_acces_dedie_id), INDEX IDX_CF34E62FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_bracelet_etanche (id BINARY(16) NOT NULL, beneficiaire_ref BINARY(16) DEFAULT NULL, roles LONGTEXT NOT NULL, support_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_bracelet_support (support_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_casier (id BINARY(16) NOT NULL, numero SMALLINT NOT NULL, zone VARCHAR(60) NOT NULL, etat VARCHAR(10) DEFAULT \'libre\' NOT NULL, bracelet_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_6EAAA822EC886B8 (bracelet_id), INDEX IDX_6EAAA822FF631228 (etablissement_id), UNIQUE INDEX uniq_casier_etab_zone_numero (etablissement_id, zone, numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_caution_casier (id BINARY(16) NOT NULL, casier_actif BINARY(16) DEFAULT NULL, montant NUMERIC(6, 2) NOT NULL, statut VARCHAR(10) DEFAULT \'encaissee\' NOT NULL, moyen_encaissement VARCHAR(30) DEFAULT NULL, regie_mouvement_ref BINARY(16) DEFAULT NULL, date_encaissement DATETIME DEFAULT NULL, date_liberation DATETIME DEFAULT NULL, casier_id BINARY(16) NOT NULL, INDEX IDX_5B9FCD9A643911C6 (casier_id), UNIQUE INDEX uniq_caution_casier_actif (casier_actif), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_creneau_bassin (id BINARY(16) NOT NULL, debut DATETIME NOT NULL, fin DATETIME NOT NULL, encadrant_requis VARCHAR(8) DEFAULT \'aucune\' NOT NULL, statut VARCHAR(10) DEFAULT \'brouillon\' NOT NULL, bassin_id BINARY(16) NOT NULL, INDEX IDX_8365861CE30215E (bassin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_creneau_public (id BINARY(16) NOT NULL, type_public VARCHAR(12) NOT NULL, jauge INT NOT NULL, occupation_courante INT DEFAULT 0 NOT NULL, creneau_bassin_id BINARY(16) NOT NULL, INDEX IDX_D975BCC48D11522 (creneau_bassin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_creneau_public_ligne (creneau_public_id BINARY(16) NOT NULL, ligne_eau_id BINARY(16) NOT NULL, INDEX IDX_5C3FD0B9B355BFED (creneau_public_id), INDEX IDX_5C3FD0B9AE92B17D (ligne_eau_id), PRIMARY KEY (creneau_public_id, ligne_eau_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_forcage_casier (id BINARY(16) NOT NULL, motif VARCHAR(255) NOT NULL, horodatage DATETIME NOT NULL, casier_id BINARY(16) NOT NULL, agent_id BINARY(16) NOT NULL, INDEX IDX_23EA693A643911C6 (casier_id), INDEX IDX_23EA693A3414710B (agent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_jauge_grand_public_calculee (id BINARY(16) NOT NULL, capacite_restante INT NOT NULL, mode_prorata VARCHAR(10) DEFAULT \'lignes\' NOT NULL, recalcule_le DATETIME NOT NULL, creneau_bassin_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_jauge_gp_creneau_bassin (creneau_bassin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_ligne_eau (id BINARY(16) NOT NULL, numero SMALLINT NOT NULL, etat VARCHAR(10) DEFAULT \'publique\' NOT NULL, surface_m2 NUMERIC(6, 2) DEFAULT NULL, bassin_id BINARY(16) NOT NULL, INDEX IDX_5650CA99E30215E (bassin_id), UNIQUE INDEX uniq_ligne_bassin_numero (bassin_id, numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_parametre_etablissement (id BINARY(16) NOT NULL, delai_forcage_casier_jours SMALLINT DEFAULT 3 NOT NULL, montant_caution_casier_defaut NUMERIC(6, 2) DEFAULT \'10.00\' NOT NULL, mode_prorata_defaut VARCHAR(10) DEFAULT \'lignes\' NOT NULL, etablissement_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_param_piscine_etablissement (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_poss (id BINARY(16) NOT NULL, perimetre VARCHAR(13) NOT NULL, base_reglementaire VARCHAR(255) DEFAULT NULL, reservations_protegees TINYINT DEFAULT 1 NOT NULL, espace_acces_id BINARY(16) NOT NULL, bassin_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_1F6B0035E30215E (bassin_id), INDEX IDX_1F6B0035FF631228 (etablissement_id), UNIQUE INDEX uniq_poss_espace_acces (espace_acces_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_qualification_encadrant (id BINARY(16) NOT NULL, type VARCHAR(8) NOT NULL, date_validite DATE NOT NULL, encadrant_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_C455FB6CFEF1BA4 (encadrant_id), INDEX IDX_C455FB6CFF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piscine_relance_casier (id BINARY(16) NOT NULL, date_relance DATETIME NOT NULL, delai_forcage_jours SMALLINT NOT NULL, casier_id BINARY(16) NOT NULL, INDEX IDX_770931C9643911C6 (casier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE piscine_affectation_encadrant ADD CONSTRAINT FK_D11DF28B8D11522 FOREIGN KEY (creneau_bassin_id) REFERENCES piscine_creneau_bassin (id)');
        $this->addSql('ALTER TABLE piscine_affectation_encadrant ADD CONSTRAINT FK_D11DF28B1A75EE38 FOREIGN KEY (qualification_id) REFERENCES piscine_qualification_encadrant (id)');
        $this->addSql('ALTER TABLE piscine_bassin ADD CONSTRAINT FK_CF34E62B6885C6C FOREIGN KEY (espace_id) REFERENCES org_espace (id)');
        $this->addSql('ALTER TABLE piscine_bassin ADD CONSTRAINT FK_CF34E6275E5FA57 FOREIGN KEY (espace_acces_dedie_id) REFERENCES acces_espace_acces (id)');
        $this->addSql('ALTER TABLE piscine_bassin ADD CONSTRAINT FK_CF34E62FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE piscine_bracelet_etanche ADD CONSTRAINT FK_57520DC0315B405 FOREIGN KEY (support_id) REFERENCES acces_support (id)');
        $this->addSql('ALTER TABLE piscine_casier ADD CONSTRAINT FK_6EAAA822EC886B8 FOREIGN KEY (bracelet_id) REFERENCES piscine_bracelet_etanche (id)');
        $this->addSql('ALTER TABLE piscine_casier ADD CONSTRAINT FK_6EAAA822FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE piscine_caution_casier ADD CONSTRAINT FK_5B9FCD9A643911C6 FOREIGN KEY (casier_id) REFERENCES piscine_casier (id)');
        $this->addSql('ALTER TABLE piscine_creneau_bassin ADD CONSTRAINT FK_8365861CE30215E FOREIGN KEY (bassin_id) REFERENCES piscine_bassin (id)');
        $this->addSql('ALTER TABLE piscine_creneau_public ADD CONSTRAINT FK_D975BCC48D11522 FOREIGN KEY (creneau_bassin_id) REFERENCES piscine_creneau_bassin (id)');
        $this->addSql('ALTER TABLE piscine_creneau_public_ligne ADD CONSTRAINT FK_5C3FD0B9B355BFED FOREIGN KEY (creneau_public_id) REFERENCES piscine_creneau_public (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE piscine_creneau_public_ligne ADD CONSTRAINT FK_5C3FD0B9AE92B17D FOREIGN KEY (ligne_eau_id) REFERENCES piscine_ligne_eau (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE piscine_forcage_casier ADD CONSTRAINT FK_23EA693A643911C6 FOREIGN KEY (casier_id) REFERENCES piscine_casier (id)');
        $this->addSql('ALTER TABLE piscine_forcage_casier ADD CONSTRAINT FK_23EA693A3414710B FOREIGN KEY (agent_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE piscine_jauge_grand_public_calculee ADD CONSTRAINT FK_48A6822E8D11522 FOREIGN KEY (creneau_bassin_id) REFERENCES piscine_creneau_bassin (id)');
        $this->addSql('ALTER TABLE piscine_ligne_eau ADD CONSTRAINT FK_5650CA99E30215E FOREIGN KEY (bassin_id) REFERENCES piscine_bassin (id)');
        $this->addSql('ALTER TABLE piscine_parametre_etablissement ADD CONSTRAINT FK_3EC57613FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE piscine_poss ADD CONSTRAINT FK_1F6B0035F353E39C FOREIGN KEY (espace_acces_id) REFERENCES acces_espace_acces (id)');
        $this->addSql('ALTER TABLE piscine_poss ADD CONSTRAINT FK_1F6B0035E30215E FOREIGN KEY (bassin_id) REFERENCES piscine_bassin (id)');
        $this->addSql('ALTER TABLE piscine_poss ADD CONSTRAINT FK_1F6B0035FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE piscine_qualification_encadrant ADD CONSTRAINT FK_C455FB6CFEF1BA4 FOREIGN KEY (encadrant_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE piscine_qualification_encadrant ADD CONSTRAINT FK_C455FB6CFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE piscine_relance_casier ADD CONSTRAINT FK_770931C9643911C6 FOREIGN KEY (casier_id) REFERENCES piscine_casier (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE piscine_affectation_encadrant DROP FOREIGN KEY FK_D11DF28B8D11522');
        $this->addSql('ALTER TABLE piscine_affectation_encadrant DROP FOREIGN KEY FK_D11DF28B1A75EE38');
        $this->addSql('ALTER TABLE piscine_bassin DROP FOREIGN KEY FK_CF34E62B6885C6C');
        $this->addSql('ALTER TABLE piscine_bassin DROP FOREIGN KEY FK_CF34E6275E5FA57');
        $this->addSql('ALTER TABLE piscine_bassin DROP FOREIGN KEY FK_CF34E62FF631228');
        $this->addSql('ALTER TABLE piscine_bracelet_etanche DROP FOREIGN KEY FK_57520DC0315B405');
        $this->addSql('ALTER TABLE piscine_casier DROP FOREIGN KEY FK_6EAAA822EC886B8');
        $this->addSql('ALTER TABLE piscine_casier DROP FOREIGN KEY FK_6EAAA822FF631228');
        $this->addSql('ALTER TABLE piscine_caution_casier DROP FOREIGN KEY FK_5B9FCD9A643911C6');
        $this->addSql('ALTER TABLE piscine_creneau_bassin DROP FOREIGN KEY FK_8365861CE30215E');
        $this->addSql('ALTER TABLE piscine_creneau_public DROP FOREIGN KEY FK_D975BCC48D11522');
        $this->addSql('ALTER TABLE piscine_creneau_public_ligne DROP FOREIGN KEY FK_5C3FD0B9B355BFED');
        $this->addSql('ALTER TABLE piscine_creneau_public_ligne DROP FOREIGN KEY FK_5C3FD0B9AE92B17D');
        $this->addSql('ALTER TABLE piscine_forcage_casier DROP FOREIGN KEY FK_23EA693A643911C6');
        $this->addSql('ALTER TABLE piscine_forcage_casier DROP FOREIGN KEY FK_23EA693A3414710B');
        $this->addSql('ALTER TABLE piscine_jauge_grand_public_calculee DROP FOREIGN KEY FK_48A6822E8D11522');
        $this->addSql('ALTER TABLE piscine_ligne_eau DROP FOREIGN KEY FK_5650CA99E30215E');
        $this->addSql('ALTER TABLE piscine_parametre_etablissement DROP FOREIGN KEY FK_3EC57613FF631228');
        $this->addSql('ALTER TABLE piscine_poss DROP FOREIGN KEY FK_1F6B0035F353E39C');
        $this->addSql('ALTER TABLE piscine_poss DROP FOREIGN KEY FK_1F6B0035E30215E');
        $this->addSql('ALTER TABLE piscine_poss DROP FOREIGN KEY FK_1F6B0035FF631228');
        $this->addSql('ALTER TABLE piscine_qualification_encadrant DROP FOREIGN KEY FK_C455FB6CFEF1BA4');
        $this->addSql('ALTER TABLE piscine_qualification_encadrant DROP FOREIGN KEY FK_C455FB6CFF631228');
        $this->addSql('ALTER TABLE piscine_relance_casier DROP FOREIGN KEY FK_770931C9643911C6');
        $this->addSql('DROP TABLE piscine_affectation_encadrant');
        $this->addSql('DROP TABLE piscine_bassin');
        $this->addSql('DROP TABLE piscine_bracelet_etanche');
        $this->addSql('DROP TABLE piscine_casier');
        $this->addSql('DROP TABLE piscine_caution_casier');
        $this->addSql('DROP TABLE piscine_creneau_bassin');
        $this->addSql('DROP TABLE piscine_creneau_public');
        $this->addSql('DROP TABLE piscine_creneau_public_ligne');
        $this->addSql('DROP TABLE piscine_forcage_casier');
        $this->addSql('DROP TABLE piscine_jauge_grand_public_calculee');
        $this->addSql('DROP TABLE piscine_ligne_eau');
        $this->addSql('DROP TABLE piscine_parametre_etablissement');
        $this->addSql('DROP TABLE piscine_poss');
        $this->addSql('DROP TABLE piscine_qualification_encadrant');
        $this->addSql('DROP TABLE piscine_relance_casier');
    }
}
