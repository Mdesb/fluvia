<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L3 Contrôle d'accès — migration structurelle : tables `acces_espace_acces`, `acces_controleur`,
 * `acces_equipement`, `acces_support`, `acces_appairage`, `acces_droit_acces`, `acces_passage`,
 * `acces_jauge_fmi`, `acces_liste_revocation`, `acces_declaration_perte_vol`, `acces_sous_reseau`
 * (+ jointure `acces_sous_reseau_espace`). Suppose les migrations socle L0 + M1 + M2 jouées d'abord
 * (FK vers `org_etablissement`, `org_espace`, `sec_utilisateur`).
 */
final class Version20260814210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "L3 (Contrôle d'accès) : topologie Espace/Contrôleur/Équipement, Support/Appairage, DroitAcces (projection), Passage (append-only), JaugeFmi, ListeRevocation, DeclarationPerteVol, SousReseau.";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE acces_appairage (id BINARY(16) NOT NULL, mode VARCHAR(12) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, support_actif BINARY(16) DEFAULT NULL, date_appairage DATETIME NOT NULL, support_id BINARY(16) NOT NULL, droit_id BINARY(16) NOT NULL, agent_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_3350CF89315B405 (support_id), INDEX IDX_3350CF895AA93370 (droit_id), INDEX IDX_3350CF893414710B (agent_id), INDEX IDX_3350CF89FF631228 (etablissement_id), UNIQUE INDEX uniq_appairage_support_actif (support_actif), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_controleur (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, itbox_ref VARCHAR(128) NOT NULL, etat VARCHAR(16) DEFAULT \'en_ligne\' NOT NULL, dernier_heartbeat DATETIME DEFAULT NULL, version_revocation INT DEFAULT 0 NOT NULL, espace_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_9948E6B2B6885C6C (espace_id), INDEX IDX_9948E6B2FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_declaration_perte_vol (id BINARY(16) NOT NULL, motif VARCHAR(255) NOT NULL, horodatage DATETIME NOT NULL, annulee TINYINT DEFAULT 0 NOT NULL, annulee_le DATETIME DEFAULT NULL, support_id BINARY(16) NOT NULL, agent_id BINARY(16) NOT NULL, annulee_par_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_DEDD891F315B405 (support_id), INDEX IDX_DEDD891F3414710B (agent_id), INDEX IDX_DEDD891F1D95B04C (annulee_par_id), INDEX IDX_DEDD891FFF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_droit_acces (id BINARY(16) NOT NULL, source_type VARCHAR(24) NOT NULL, billet_support_ref BINARY(16) DEFAULT NULL, produit_ref BINARY(16) DEFAULT NULL, fenetre_debut DATETIME DEFAULT NULL, fenetre_fin DATETIME DEFAULT NULL, credit_restant INT DEFAULT NULL, marge_avance_defaut INT DEFAULT NULL, marge_retard_defaut INT DEFAULT NULL, statut_projection VARCHAR(12) DEFAULT \'valide\' NOT NULL, synchronise_le DATETIME DEFAULT NULL, sous_reseau_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_922F88815F54FC43 (sous_reseau_id), INDEX IDX_922F8881FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_equipement (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, type VARCHAR(16) NOT NULL, sens VARCHAR(16) DEFAULT NULL, anti_passback_actif TINYINT DEFAULT NULL, anti_passback_delai INT DEFAULT NULL, marge_avance INT DEFAULT 0 NOT NULL, marge_retard INT DEFAULT 0 NOT NULL, controleur_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_281D6F05B13E6101 (controleur_id), INDEX IDX_281D6F05FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_espace_acces (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, seuil_fmi INT NOT NULL, mode_seuil VARCHAR(12) DEFAULT \'blocage\' NOT NULL, pre_alerte_pct SMALLINT DEFAULT NULL, anti_passback_actif TINYINT DEFAULT 1 NOT NULL, anti_passback_delai INT DEFAULT 300 NOT NULL, recalage_ouverture VARCHAR(16) DEFAULT \'remise_a_zero\' NOT NULL, espace_socle_id BINARY(16) NOT NULL, sous_reseau_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_90BD8611720FD58C (espace_socle_id), INDEX IDX_90BD86115F54FC43 (sous_reseau_id), INDEX IDX_90BD8611FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_jauge_fmi (id BINARY(16) NOT NULL, valeur_courante INT DEFAULT 0 NOT NULL, seuil INT DEFAULT 0 NOT NULL, mode VARCHAR(12) DEFAULT \'blocage\' NOT NULL, cumul_jour INT DEFAULT 0 NOT NULL, date_reference DATE NOT NULL, espace_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_jauge_espace (espace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_liste_revocation (id BINARY(16) NOT NULL, version INT NOT NULL, supports_bloques JSON NOT NULL, genere_le DATETIME NOT NULL, controleur_id BINARY(16) NOT NULL, INDEX IDX_B063F673B13E6101 (controleur_id), UNIQUE INDEX uniq_revocation_controleur_version (controleur_id, version), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_passage (id BINARY(16) NOT NULL, horodatage DATETIME NOT NULL, sens VARCHAR(12) NOT NULL, resultat VARCHAR(12) NOT NULL, motif VARCHAR(255) DEFAULT NULL, code_motif VARCHAR(32) DEFAULT NULL, origine_hors_ligne TINYINT DEFAULT 0 NOT NULL, en_conflit TINYINT DEFAULT 0 NOT NULL, cle_idempotence BINARY(16) NOT NULL, espace_id BINARY(16) NOT NULL, controleur_id BINARY(16) DEFAULT NULL, equipement_id BINARY(16) DEFAULT NULL, support_id BINARY(16) DEFAULT NULL, droit_id BINARY(16) DEFAULT NULL, agent_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_8E448ACFB6885C6C (espace_id), INDEX IDX_8E448ACFB13E6101 (controleur_id), INDEX IDX_8E448ACF806F0F5C (equipement_id), INDEX IDX_8E448ACF315B405 (support_id), INDEX IDX_8E448ACF5AA93370 (droit_id), INDEX IDX_8E448ACF3414710B (agent_id), INDEX IDX_8E448ACFFF631228 (etablissement_id), UNIQUE INDEX uniq_passage_cle_idempotence (cle_idempotence), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_sous_reseau (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, actif TINYINT DEFAULT 0 NOT NULL, droits_eligibles_ref JSON DEFAULT NULL, seuil_fmi_agrege INT DEFAULT NULL, anti_passback_delai INT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_sous_reseau_espace (sous_reseau_id BINARY(16) NOT NULL, espace_acces_id BINARY(16) NOT NULL, INDEX IDX_C13ABDD95F54FC43 (sous_reseau_id), INDEX IDX_C13ABDD9F353E39C (espace_acces_id), PRIMARY KEY (sous_reseau_id, espace_acces_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_support (id BINARY(16) NOT NULL, identifiant VARCHAR(128) NOT NULL, type VARCHAR(12) NOT NULL, statut VARCHAR(12) DEFAULT \'actif\' NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_2565EE0DFF631228 (etablissement_id), UNIQUE INDEX uniq_support_identifiant (identifiant), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE acces_appairage ADD CONSTRAINT FK_3350CF89315B405 FOREIGN KEY (support_id) REFERENCES acces_support (id)');
        $this->addSql('ALTER TABLE acces_appairage ADD CONSTRAINT FK_3350CF895AA93370 FOREIGN KEY (droit_id) REFERENCES acces_droit_acces (id)');
        $this->addSql('ALTER TABLE acces_appairage ADD CONSTRAINT FK_3350CF893414710B FOREIGN KEY (agent_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE acces_appairage ADD CONSTRAINT FK_3350CF89FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE acces_controleur ADD CONSTRAINT FK_9948E6B2B6885C6C FOREIGN KEY (espace_id) REFERENCES acces_espace_acces (id)');
        $this->addSql('ALTER TABLE acces_controleur ADD CONSTRAINT FK_9948E6B2FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE acces_declaration_perte_vol ADD CONSTRAINT FK_DEDD891F315B405 FOREIGN KEY (support_id) REFERENCES acces_support (id)');
        $this->addSql('ALTER TABLE acces_declaration_perte_vol ADD CONSTRAINT FK_DEDD891F3414710B FOREIGN KEY (agent_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE acces_declaration_perte_vol ADD CONSTRAINT FK_DEDD891F1D95B04C FOREIGN KEY (annulee_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE acces_declaration_perte_vol ADD CONSTRAINT FK_DEDD891FFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE acces_droit_acces ADD CONSTRAINT FK_922F88815F54FC43 FOREIGN KEY (sous_reseau_id) REFERENCES acces_sous_reseau (id)');
        $this->addSql('ALTER TABLE acces_droit_acces ADD CONSTRAINT FK_922F8881FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE acces_equipement ADD CONSTRAINT FK_281D6F05B13E6101 FOREIGN KEY (controleur_id) REFERENCES acces_controleur (id)');
        $this->addSql('ALTER TABLE acces_equipement ADD CONSTRAINT FK_281D6F05FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE acces_espace_acces ADD CONSTRAINT FK_90BD8611720FD58C FOREIGN KEY (espace_socle_id) REFERENCES org_espace (id)');
        $this->addSql('ALTER TABLE acces_espace_acces ADD CONSTRAINT FK_90BD86115F54FC43 FOREIGN KEY (sous_reseau_id) REFERENCES acces_sous_reseau (id)');
        $this->addSql('ALTER TABLE acces_espace_acces ADD CONSTRAINT FK_90BD8611FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE acces_jauge_fmi ADD CONSTRAINT FK_E4CB849DB6885C6C FOREIGN KEY (espace_id) REFERENCES acces_espace_acces (id)');
        $this->addSql('ALTER TABLE acces_liste_revocation ADD CONSTRAINT FK_B063F673B13E6101 FOREIGN KEY (controleur_id) REFERENCES acces_controleur (id)');
        $this->addSql('ALTER TABLE acces_passage ADD CONSTRAINT FK_8E448ACFB6885C6C FOREIGN KEY (espace_id) REFERENCES acces_espace_acces (id)');
        $this->addSql('ALTER TABLE acces_passage ADD CONSTRAINT FK_8E448ACFB13E6101 FOREIGN KEY (controleur_id) REFERENCES acces_controleur (id)');
        $this->addSql('ALTER TABLE acces_passage ADD CONSTRAINT FK_8E448ACF806F0F5C FOREIGN KEY (equipement_id) REFERENCES acces_equipement (id)');
        $this->addSql('ALTER TABLE acces_passage ADD CONSTRAINT FK_8E448ACF315B405 FOREIGN KEY (support_id) REFERENCES acces_support (id)');
        $this->addSql('ALTER TABLE acces_passage ADD CONSTRAINT FK_8E448ACF5AA93370 FOREIGN KEY (droit_id) REFERENCES acces_droit_acces (id)');
        $this->addSql('ALTER TABLE acces_passage ADD CONSTRAINT FK_8E448ACF3414710B FOREIGN KEY (agent_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE acces_passage ADD CONSTRAINT FK_8E448ACFFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE acces_sous_reseau_espace ADD CONSTRAINT FK_C13ABDD95F54FC43 FOREIGN KEY (sous_reseau_id) REFERENCES acces_sous_reseau (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE acces_sous_reseau_espace ADD CONSTRAINT FK_C13ABDD9F353E39C FOREIGN KEY (espace_acces_id) REFERENCES acces_espace_acces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE acces_support ADD CONSTRAINT FK_2565EE0DFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE acces_appairage DROP FOREIGN KEY FK_3350CF89315B405');
        $this->addSql('ALTER TABLE acces_appairage DROP FOREIGN KEY FK_3350CF895AA93370');
        $this->addSql('ALTER TABLE acces_appairage DROP FOREIGN KEY FK_3350CF893414710B');
        $this->addSql('ALTER TABLE acces_appairage DROP FOREIGN KEY FK_3350CF89FF631228');
        $this->addSql('ALTER TABLE acces_controleur DROP FOREIGN KEY FK_9948E6B2B6885C6C');
        $this->addSql('ALTER TABLE acces_controleur DROP FOREIGN KEY FK_9948E6B2FF631228');
        $this->addSql('ALTER TABLE acces_declaration_perte_vol DROP FOREIGN KEY FK_DEDD891F315B405');
        $this->addSql('ALTER TABLE acces_declaration_perte_vol DROP FOREIGN KEY FK_DEDD891F3414710B');
        $this->addSql('ALTER TABLE acces_declaration_perte_vol DROP FOREIGN KEY FK_DEDD891F1D95B04C');
        $this->addSql('ALTER TABLE acces_declaration_perte_vol DROP FOREIGN KEY FK_DEDD891FFF631228');
        $this->addSql('ALTER TABLE acces_droit_acces DROP FOREIGN KEY FK_922F88815F54FC43');
        $this->addSql('ALTER TABLE acces_droit_acces DROP FOREIGN KEY FK_922F8881FF631228');
        $this->addSql('ALTER TABLE acces_equipement DROP FOREIGN KEY FK_281D6F05B13E6101');
        $this->addSql('ALTER TABLE acces_equipement DROP FOREIGN KEY FK_281D6F05FF631228');
        $this->addSql('ALTER TABLE acces_espace_acces DROP FOREIGN KEY FK_90BD8611720FD58C');
        $this->addSql('ALTER TABLE acces_espace_acces DROP FOREIGN KEY FK_90BD86115F54FC43');
        $this->addSql('ALTER TABLE acces_espace_acces DROP FOREIGN KEY FK_90BD8611FF631228');
        $this->addSql('ALTER TABLE acces_jauge_fmi DROP FOREIGN KEY FK_E4CB849DB6885C6C');
        $this->addSql('ALTER TABLE acces_liste_revocation DROP FOREIGN KEY FK_B063F673B13E6101');
        $this->addSql('ALTER TABLE acces_passage DROP FOREIGN KEY FK_8E448ACFB6885C6C');
        $this->addSql('ALTER TABLE acces_passage DROP FOREIGN KEY FK_8E448ACFB13E6101');
        $this->addSql('ALTER TABLE acces_passage DROP FOREIGN KEY FK_8E448ACF806F0F5C');
        $this->addSql('ALTER TABLE acces_passage DROP FOREIGN KEY FK_8E448ACF315B405');
        $this->addSql('ALTER TABLE acces_passage DROP FOREIGN KEY FK_8E448ACF5AA93370');
        $this->addSql('ALTER TABLE acces_passage DROP FOREIGN KEY FK_8E448ACF3414710B');
        $this->addSql('ALTER TABLE acces_passage DROP FOREIGN KEY FK_8E448ACFFF631228');
        $this->addSql('ALTER TABLE acces_sous_reseau_espace DROP FOREIGN KEY FK_C13ABDD95F54FC43');
        $this->addSql('ALTER TABLE acces_sous_reseau_espace DROP FOREIGN KEY FK_C13ABDD9F353E39C');
        $this->addSql('ALTER TABLE acces_support DROP FOREIGN KEY FK_2565EE0DFF631228');
        $this->addSql('DROP TABLE acces_appairage');
        $this->addSql('DROP TABLE acces_controleur');
        $this->addSql('DROP TABLE acces_declaration_perte_vol');
        $this->addSql('DROP TABLE acces_droit_acces');
        $this->addSql('DROP TABLE acces_equipement');
        $this->addSql('DROP TABLE acces_espace_acces');
        $this->addSql('DROP TABLE acces_jauge_fmi');
        $this->addSql('DROP TABLE acces_liste_revocation');
        $this->addSql('DROP TABLE acces_passage');
        $this->addSql('DROP TABLE acces_sous_reseau');
        $this->addSql('DROP TABLE acces_sous_reseau_espace');
        $this->addSql('DROP TABLE acces_support');
    }
}
