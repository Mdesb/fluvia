<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260814230758 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE compta_bordereau_payfip (id BINARY(16) NOT NULL, vente_origine BINARY(16) NOT NULL, reference_transaction VARCHAR(64) NOT NULL, statut_retour VARCHAR(12) DEFAULT \'en_attente\' NOT NULL, vente_rapprochee TINYINT DEFAULT 0 NOT NULL, nb_tentatives_rejeu INT DEFAULT 0 NOT NULL, date_heure DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_bordereau_versement (id BINARY(16) NOT NULL, date_versement DATE NOT NULL, montant_centimes INT NOT NULL, justificatifs JSON DEFAULT NULL, regie_id BINARY(16) NOT NULL, ecriture_generee_id BINARY(16) DEFAULT NULL, INDEX IDX_20AA983946F21B0B (regie_id), INDEX IDX_20AA9839EE2CA29 (ecriture_generee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_compte_comptable (id BINARY(16) NOT NULL, numero VARCHAR(16) NOT NULL, libelle VARCHAR(160) NOT NULL, sens VARCHAR(8) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, profil_exploitant_id BINARY(16) NOT NULL, INDEX IDX_F8E037EE53ABECD4 (profil_exploitant_id), UNIQUE INDEX uniq_compte_profil_numero (profil_exploitant_id, numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_declaration_ereporting (id BINARY(16) NOT NULL, periode_debut DATE NOT NULL, periode_fin DATE NOT NULL, siren VARCHAR(9) NOT NULL, agregat_par_jour_taux JSON NOT NULL, statut_envoi VARCHAR(10) DEFAULT \'prepare\' NOT NULL, profil_exploitant_id BINARY(16) NOT NULL, INDEX IDX_F811885153ABECD4 (profil_exploitant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_ecriture_comptable (id BINARY(16) NOT NULL, date_ecriture DATE NOT NULL, libelle VARCHAR(255) DEFAULT NULL, statut VARCHAR(12) DEFAULT \'provisoire\' NOT NULL, vente_origine BINARY(16) DEFAULT NULL, numero_sequence BIGINT NOT NULL, empreinte VARCHAR(128) NOT NULL, empreinte_precedente VARCHAR(128) DEFAULT NULL, signature VARCHAR(512) NOT NULL, cree_le DATETIME NOT NULL, profil_exploitant_id BINARY(16) NOT NULL, journal_id BINARY(16) NOT NULL, periode_id BINARY(16) NOT NULL, piece_extourne_de_id BINARY(16) DEFAULT NULL, INDEX IDX_F17F4DA553ABECD4 (profil_exploitant_id), INDEX IDX_F17F4DA5478E8802 (journal_id), INDEX IDX_F17F4DA5F384C1CF (periode_id), INDEX IDX_F17F4DA5153E8777 (piece_extourne_de_id), UNIQUE INDEX uniq_ecriture_profil_journal_sequence (profil_exploitant_id, journal_id, numero_sequence), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_etalement_pca (id BINARY(16) NOT NULL, produit BINARY(16) NOT NULL, vente_origine BINARY(16) NOT NULL, nature VARCHAR(16) NOT NULL, methode VARCHAR(16) NOT NULL, periode_service_debut DATE DEFAULT NULL, periode_service_fin DATE DEFAULT NULL, montant_reporte_centimes INT NOT NULL, reste_aservir_centimes INT NOT NULL, nb_unites_carte INT DEFAULT NULL, identifiant_support VARCHAR(128) DEFAULT NULL, solde_residuel_traite TINYINT DEFAULT 0 NOT NULL, profil_exploitant_id BINARY(16) NOT NULL, compte_report_id BINARY(16) NOT NULL, INDEX IDX_3161A7B653ABECD4 (profil_exploitant_id), INDEX IDX_3161A7B6885C9AF2 (compte_report_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_export_comptable (id BINARY(16) NOT NULL, format VARCHAR(20) NOT NULL, periode_debut DATE NOT NULL, periode_fin DATE NOT NULL, planifie TINYINT DEFAULT 0 NOT NULL, frequence VARCHAR(16) DEFAULT NULL, destinataire VARCHAR(160) DEFAULT NULL, statut VARCHAR(16) DEFAULT \'genere\' NOT NULL, anomalies JSON DEFAULT NULL, genere_titre_regularisation TINYINT DEFAULT 0 NOT NULL, contenu LONGTEXT DEFAULT NULL, profil_exploitant_id BINARY(16) NOT NULL, INDEX IDX_B6684F6C53ABECD4 (profil_exploitant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_facture_b2g (id BINARY(16) NOT NULL, client_ref BINARY(16) NOT NULL, numero_engagement VARCHAR(64) DEFAULT NULL, service_executant VARCHAR(120) DEFAULT NULL, statut_envoi VARCHAR(10) DEFAULT \'prepare\' NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_journal (id BINARY(16) NOT NULL, code VARCHAR(8) NOT NULL, libelle VARCHAR(120) NOT NULL, profil_exploitant_id BINARY(16) NOT NULL, INDEX IDX_242C8A1753ABECD4 (profil_exploitant_id), UNIQUE INDEX uniq_journal_profil_code (profil_exploitant_id, code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_lettrage_ecriture (id BINARY(16) NOT NULL, date_lettrage DATE NOT NULL, ligne_id BINARY(16) NOT NULL, auteur_id BINARY(16) NOT NULL, INDEX IDX_A38404AE5A438E76 (ligne_id), INDEX IDX_A38404AE60BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_ligne_ecriture (id BINARY(16) NOT NULL, debit_centimes INT NOT NULL, credit_centimes INT NOT NULL, axe_site VARCHAR(64) DEFAULT NULL, axe_activite VARCHAR(64) DEFAULT NULL, axe_financeur VARCHAR(64) DEFAULT NULL, libelle VARCHAR(255) DEFAULT NULL, ecriture_id BINARY(16) NOT NULL, compte_id BINARY(16) NOT NULL, taux_tva_id BINARY(16) NOT NULL, INDEX IDX_48CB06293407A4D0 (ecriture_id), INDEX IDX_48CB0629F2C56620 (compte_id), INDEX IDX_48CB0629F7FEBCCE (taux_tva_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_mapping_comptable (id BINARY(16) NOT NULL, categorie BINARY(16) NOT NULL, profil_exploitant_id BINARY(16) NOT NULL, compte_produit_id BINARY(16) NOT NULL, taux_tva_id BINARY(16) NOT NULL, INDEX IDX_4BE5760753ABECD4 (profil_exploitant_id), INDEX IDX_4BE57607C720A145 (compte_produit_id), INDEX IDX_4BE57607F7FEBCCE (taux_tva_id), UNIQUE INDEX uniq_mapping_profil_categorie (profil_exploitant_id, categorie), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_mouvement_pca (id BINARY(16) NOT NULL, type VARCHAR(10) NOT NULL, date_mouvement DATE NOT NULL, montant_centimes INT NOT NULL, fait_generateur VARCHAR(24) NOT NULL, passage_origine BINARY(16) DEFAULT NULL, etalement_id BINARY(16) NOT NULL, ecriture_liee_id BINARY(16) NOT NULL, INDEX IDX_384B9B4170A11E2E (etalement_id), INDEX IDX_384B9B4119373C33 (ecriture_liee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_moyen_paiement (id BINARY(16) NOT NULL, code VARCHAR(32) NOT NULL, libelle VARCHAR(80) NOT NULL, autorise_rendu TINYINT DEFAULT 0 NOT NULL, exige_reference TINYINT DEFAULT 0 NOT NULL, autorise_differe TINYINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_moyen_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_periode_comptable (id BINARY(16) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, statut VARCHAR(10) DEFAULT \'ouverte\' NOT NULL, etat_cloture JSON DEFAULT NULL, profil_exploitant_id BINARY(16) NOT NULL, INDEX IDX_E8E7FC2F53ABECD4 (profil_exploitant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_profil_exploitant (id BINARY(16) NOT NULL, type VARCHAR(16) NOT NULL, referentiel_comptable VARCHAR(8) NOT NULL, siren VARCHAR(9) NOT NULL, verrouille TINYINT DEFAULT 0 NOT NULL, parametres JSON NOT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, etablissement_principal_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_74B2875D980FA96F (etablissement_principal_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_profil_etablissement_rattache (profil_exploitant_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_E7C9270253ABECD4 (profil_exploitant_id), INDEX IDX_E7C92702FF631228 (etablissement_id), PRIMARY KEY (profil_exploitant_id, etablissement_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_qualification_equipement (id BINARY(16) NOT NULL, qualification VARCHAR(4) NOT NULL, espace_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_qualif_espace (espace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_rad (id BINARY(16) NOT NULL, compte_exploitation JSON DEFAULT NULL, profil_exploitant_id BINARY(16) NOT NULL, exercice_id BINARY(16) NOT NULL, INDEX IDX_3BC5B17653ABECD4 (profil_exploitant_id), INDEX IDX_3BC5B17689D40298 (exercice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_redevance (id BINARY(16) NOT NULL, formule_contractuelle LONGTEXT DEFAULT NULL, assiette_centimes INT DEFAULT NULL, montant_centimes INT DEFAULT NULL, rad_id BINARY(16) NOT NULL, INDEX IDX_3089241D7E3E2FE2 (rad_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_regie_recettes (id BINARY(16) NOT NULL, libelle VARCHAR(160) NOT NULL, acte_nomination VARCHAR(255) DEFAULT NULL, modes_autorises JSON NOT NULL, plafond_encaisse_centimes INT NOT NULL, solde_encaisse_centimes INT DEFAULT 0 NOT NULL, periodicite_versement VARCHAR(16) DEFAULT \'quotidien\' NOT NULL, profil_exploitant_id BINARY(16) NOT NULL, INDEX IDX_BE22A97A53ABECD4 (profil_exploitant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_taux_tva (id BINARY(16) NOT NULL, taux NUMERIC(5, 2) NOT NULL, libelle VARCHAR(80) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, profil_exploitant_id BINARY(16) NOT NULL, INDEX IDX_FAA9536F53ABECD4 (profil_exploitant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compta_vente_impayee_regie (id BINARY(16) NOT NULL, vente_origine BINARY(16) NOT NULL, motif VARCHAR(255) NOT NULL, date_marquage DATE NOT NULL, UNIQUE INDEX uniq_impayee_vente (vente_origine), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE compta_bordereau_versement ADD CONSTRAINT FK_20AA983946F21B0B FOREIGN KEY (regie_id) REFERENCES compta_regie_recettes (id)');
        $this->addSql('ALTER TABLE compta_bordereau_versement ADD CONSTRAINT FK_20AA9839EE2CA29 FOREIGN KEY (ecriture_generee_id) REFERENCES compta_ecriture_comptable (id)');
        $this->addSql('ALTER TABLE compta_compte_comptable ADD CONSTRAINT FK_F8E037EE53ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE compta_declaration_ereporting ADD CONSTRAINT FK_F811885153ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE compta_ecriture_comptable ADD CONSTRAINT FK_F17F4DA553ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE compta_ecriture_comptable ADD CONSTRAINT FK_F17F4DA5478E8802 FOREIGN KEY (journal_id) REFERENCES compta_journal (id)');
        $this->addSql('ALTER TABLE compta_ecriture_comptable ADD CONSTRAINT FK_F17F4DA5F384C1CF FOREIGN KEY (periode_id) REFERENCES compta_periode_comptable (id)');
        $this->addSql('ALTER TABLE compta_ecriture_comptable ADD CONSTRAINT FK_F17F4DA5153E8777 FOREIGN KEY (piece_extourne_de_id) REFERENCES compta_ecriture_comptable (id)');
        $this->addSql('ALTER TABLE compta_etalement_pca ADD CONSTRAINT FK_3161A7B653ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE compta_etalement_pca ADD CONSTRAINT FK_3161A7B6885C9AF2 FOREIGN KEY (compte_report_id) REFERENCES compta_compte_comptable (id)');
        $this->addSql('ALTER TABLE compta_export_comptable ADD CONSTRAINT FK_B6684F6C53ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE compta_journal ADD CONSTRAINT FK_242C8A1753ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE compta_lettrage_ecriture ADD CONSTRAINT FK_A38404AE5A438E76 FOREIGN KEY (ligne_id) REFERENCES compta_ligne_ecriture (id)');
        $this->addSql('ALTER TABLE compta_lettrage_ecriture ADD CONSTRAINT FK_A38404AE60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE compta_ligne_ecriture ADD CONSTRAINT FK_48CB06293407A4D0 FOREIGN KEY (ecriture_id) REFERENCES compta_ecriture_comptable (id)');
        $this->addSql('ALTER TABLE compta_ligne_ecriture ADD CONSTRAINT FK_48CB0629F2C56620 FOREIGN KEY (compte_id) REFERENCES compta_compte_comptable (id)');
        $this->addSql('ALTER TABLE compta_ligne_ecriture ADD CONSTRAINT FK_48CB0629F7FEBCCE FOREIGN KEY (taux_tva_id) REFERENCES compta_taux_tva (id)');
        $this->addSql('ALTER TABLE compta_mapping_comptable ADD CONSTRAINT FK_4BE5760753ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE compta_mapping_comptable ADD CONSTRAINT FK_4BE57607C720A145 FOREIGN KEY (compte_produit_id) REFERENCES compta_compte_comptable (id)');
        $this->addSql('ALTER TABLE compta_mapping_comptable ADD CONSTRAINT FK_4BE57607F7FEBCCE FOREIGN KEY (taux_tva_id) REFERENCES compta_taux_tva (id)');
        $this->addSql('ALTER TABLE compta_mouvement_pca ADD CONSTRAINT FK_384B9B4170A11E2E FOREIGN KEY (etalement_id) REFERENCES compta_etalement_pca (id)');
        $this->addSql('ALTER TABLE compta_mouvement_pca ADD CONSTRAINT FK_384B9B4119373C33 FOREIGN KEY (ecriture_liee_id) REFERENCES compta_ecriture_comptable (id)');
        $this->addSql('ALTER TABLE compta_periode_comptable ADD CONSTRAINT FK_E8E7FC2F53ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE compta_profil_exploitant ADD CONSTRAINT FK_74B2875D980FA96F FOREIGN KEY (etablissement_principal_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE compta_profil_etablissement_rattache ADD CONSTRAINT FK_E7C9270253ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compta_profil_etablissement_rattache ADD CONSTRAINT FK_E7C92702FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compta_qualification_equipement ADD CONSTRAINT FK_3527BCC0B6885C6C FOREIGN KEY (espace_id) REFERENCES org_espace (id)');
        $this->addSql('ALTER TABLE compta_rad ADD CONSTRAINT FK_3BC5B17653ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE compta_rad ADD CONSTRAINT FK_3BC5B17689D40298 FOREIGN KEY (exercice_id) REFERENCES compta_periode_comptable (id)');
        $this->addSql('ALTER TABLE compta_redevance ADD CONSTRAINT FK_3089241D7E3E2FE2 FOREIGN KEY (rad_id) REFERENCES compta_rad (id)');
        $this->addSql('ALTER TABLE compta_regie_recettes ADD CONSTRAINT FK_BE22A97A53ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE compta_taux_tva ADD CONSTRAINT FK_FAA9536F53ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compta_bordereau_versement DROP FOREIGN KEY FK_20AA983946F21B0B');
        $this->addSql('ALTER TABLE compta_bordereau_versement DROP FOREIGN KEY FK_20AA9839EE2CA29');
        $this->addSql('ALTER TABLE compta_compte_comptable DROP FOREIGN KEY FK_F8E037EE53ABECD4');
        $this->addSql('ALTER TABLE compta_declaration_ereporting DROP FOREIGN KEY FK_F811885153ABECD4');
        $this->addSql('ALTER TABLE compta_ecriture_comptable DROP FOREIGN KEY FK_F17F4DA553ABECD4');
        $this->addSql('ALTER TABLE compta_ecriture_comptable DROP FOREIGN KEY FK_F17F4DA5478E8802');
        $this->addSql('ALTER TABLE compta_ecriture_comptable DROP FOREIGN KEY FK_F17F4DA5F384C1CF');
        $this->addSql('ALTER TABLE compta_ecriture_comptable DROP FOREIGN KEY FK_F17F4DA5153E8777');
        $this->addSql('ALTER TABLE compta_etalement_pca DROP FOREIGN KEY FK_3161A7B653ABECD4');
        $this->addSql('ALTER TABLE compta_etalement_pca DROP FOREIGN KEY FK_3161A7B6885C9AF2');
        $this->addSql('ALTER TABLE compta_export_comptable DROP FOREIGN KEY FK_B6684F6C53ABECD4');
        $this->addSql('ALTER TABLE compta_journal DROP FOREIGN KEY FK_242C8A1753ABECD4');
        $this->addSql('ALTER TABLE compta_lettrage_ecriture DROP FOREIGN KEY FK_A38404AE5A438E76');
        $this->addSql('ALTER TABLE compta_lettrage_ecriture DROP FOREIGN KEY FK_A38404AE60BB6FE6');
        $this->addSql('ALTER TABLE compta_ligne_ecriture DROP FOREIGN KEY FK_48CB06293407A4D0');
        $this->addSql('ALTER TABLE compta_ligne_ecriture DROP FOREIGN KEY FK_48CB0629F2C56620');
        $this->addSql('ALTER TABLE compta_ligne_ecriture DROP FOREIGN KEY FK_48CB0629F7FEBCCE');
        $this->addSql('ALTER TABLE compta_mapping_comptable DROP FOREIGN KEY FK_4BE5760753ABECD4');
        $this->addSql('ALTER TABLE compta_mapping_comptable DROP FOREIGN KEY FK_4BE57607C720A145');
        $this->addSql('ALTER TABLE compta_mapping_comptable DROP FOREIGN KEY FK_4BE57607F7FEBCCE');
        $this->addSql('ALTER TABLE compta_mouvement_pca DROP FOREIGN KEY FK_384B9B4170A11E2E');
        $this->addSql('ALTER TABLE compta_mouvement_pca DROP FOREIGN KEY FK_384B9B4119373C33');
        $this->addSql('ALTER TABLE compta_periode_comptable DROP FOREIGN KEY FK_E8E7FC2F53ABECD4');
        $this->addSql('ALTER TABLE compta_profil_exploitant DROP FOREIGN KEY FK_74B2875D980FA96F');
        $this->addSql('ALTER TABLE compta_profil_etablissement_rattache DROP FOREIGN KEY FK_E7C9270253ABECD4');
        $this->addSql('ALTER TABLE compta_profil_etablissement_rattache DROP FOREIGN KEY FK_E7C92702FF631228');
        $this->addSql('ALTER TABLE compta_qualification_equipement DROP FOREIGN KEY FK_3527BCC0B6885C6C');
        $this->addSql('ALTER TABLE compta_rad DROP FOREIGN KEY FK_3BC5B17653ABECD4');
        $this->addSql('ALTER TABLE compta_rad DROP FOREIGN KEY FK_3BC5B17689D40298');
        $this->addSql('ALTER TABLE compta_redevance DROP FOREIGN KEY FK_3089241D7E3E2FE2');
        $this->addSql('ALTER TABLE compta_regie_recettes DROP FOREIGN KEY FK_BE22A97A53ABECD4');
        $this->addSql('ALTER TABLE compta_taux_tva DROP FOREIGN KEY FK_FAA9536F53ABECD4');
        $this->addSql('DROP TABLE compta_bordereau_payfip');
        $this->addSql('DROP TABLE compta_bordereau_versement');
        $this->addSql('DROP TABLE compta_compte_comptable');
        $this->addSql('DROP TABLE compta_declaration_ereporting');
        $this->addSql('DROP TABLE compta_ecriture_comptable');
        $this->addSql('DROP TABLE compta_etalement_pca');
        $this->addSql('DROP TABLE compta_export_comptable');
        $this->addSql('DROP TABLE compta_facture_b2g');
        $this->addSql('DROP TABLE compta_journal');
        $this->addSql('DROP TABLE compta_lettrage_ecriture');
        $this->addSql('DROP TABLE compta_ligne_ecriture');
        $this->addSql('DROP TABLE compta_mapping_comptable');
        $this->addSql('DROP TABLE compta_mouvement_pca');
        $this->addSql('DROP TABLE compta_moyen_paiement');
        $this->addSql('DROP TABLE compta_periode_comptable');
        $this->addSql('DROP TABLE compta_profil_exploitant');
        $this->addSql('DROP TABLE compta_profil_etablissement_rattache');
        $this->addSql('DROP TABLE compta_qualification_equipement');
        $this->addSql('DROP TABLE compta_rad');
        $this->addSql('DROP TABLE compta_redevance');
        $this->addSql('DROP TABLE compta_regie_recettes');
        $this->addSql('DROP TABLE compta_taux_tva');
        $this->addSql('DROP TABLE compta_vente_impayee_regie');
    }
}
