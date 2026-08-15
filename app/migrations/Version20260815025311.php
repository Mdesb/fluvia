<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L5 (CRM noyau, M4) : tables `crm_*` (Client, Famille, Beneficiaire, PorteMonnaieVirtuel,
 * MouvementPmv, ParametrePmvEtablissement, Consentement, DemandeRGPD, RegleConservation,
 * JournalFusion) + `sec_utilisateur.client_lie` (lien `Utilisateur`↔`Client` pour les permissions
 * `crm.*_soi`, §6/§10.8 plan-crm.md). Suppose les migrations socle L0 + M1 + M2 + L4 déjà jouées
 * (FK vers `org_groupe`/`org_etablissement`/`sec_utilisateur`).
 */
final class Version20260815025311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'L5 CRM noyau (M4) : tables crm_* + sec_utilisateur.client_lie.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE crm_beneficiaire (id BINARY(16) NOT NULL, role VARCHAR(24) NOT NULL, autorisations JSON DEFAULT NULL, date_ajout DATETIME NOT NULL, date_retrait DATETIME DEFAULT NULL, famille_id BINARY(16) NOT NULL, client_id BINARY(16) NOT NULL, INDEX IDX_8DA01A4697A77B84 (famille_id), INDEX IDX_8DA01A4619EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_client (id BINARY(16) NOT NULL, type VARCHAR(12) NOT NULL, civilite VARCHAR(8) DEFAULT NULL, nom VARCHAR(120) DEFAULT NULL, prenom VARCHAR(120) DEFAULT NULL, raison_sociale VARCHAR(180) DEFAULT NULL, siret VARCHAR(14) DEFAULT NULL, date_naissance DATE DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, telephone VARCHAR(32) DEFAULT NULL, adresse JSON DEFAULT NULL, champ_manuel JSON DEFAULT NULL, statut VARCHAR(12) DEFAULT \'actif\' NOT NULL, date_derniere_visite DATETIME DEFAULT NULL, ca_cumule NUMERIC(10, 2) DEFAULT \'0.00\', date_creation DATETIME NOT NULL, date_maj DATETIME DEFAULT NULL, maj_par VARCHAR(180) DEFAULT NULL, groupe_id BINARY(16) NOT NULL, etablissement_creation_id BINARY(16) NOT NULL, fusionne_dans_id BINARY(16) DEFAULT NULL, cree_par_id BINARY(16) DEFAULT NULL, INDEX IDX_EFFAB5947A45358C (groupe_id), INDEX IDX_EFFAB59413C9E436 (etablissement_creation_id), INDEX IDX_EFFAB594DBB9BC49 (fusionne_dans_id), INDEX IDX_EFFAB594FC29C013 (cree_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_consentement (id BINARY(16) NOT NULL, canal VARCHAR(12) NOT NULL, etat VARCHAR(12) NOT NULL, date_recueil DATETIME NOT NULL, date_expiration DATE DEFAULT NULL, source VARCHAR(64) NOT NULL, recueilli_par_representant TINYINT DEFAULT 0 NOT NULL, client_id BINARY(16) NOT NULL, INDEX IDX_7470E86A19EB6921 (client_id), INDEX idx_consentement_client_canal_date (client_id, canal, date_recueil), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_demande_rgpd (id BINARY(16) NOT NULL, type VARCHAR(16) NOT NULL, statut VARCHAR(12) DEFAULT \'recue\' NOT NULL, date_demande DATETIME NOT NULL, date_traitement DATETIME DEFAULT NULL, client_id BINARY(16) NOT NULL, traite_par_id BINARY(16) DEFAULT NULL, INDEX IDX_934F068819EB6921 (client_id), INDEX IDX_934F0688167FABE8 (traite_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_famille (id BINARY(16) NOT NULL, libelle VARCHAR(120) DEFAULT NULL, statut VARCHAR(12) DEFAULT \'active\' NOT NULL, date_creation DATETIME NOT NULL, groupe_id BINARY(16) NOT NULL, payeur_principal_id BINARY(16) NOT NULL, INDEX IDX_C838BE847A45358C (groupe_id), INDEX IDX_C838BE84C0F84BDD (payeur_principal_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_journal_fusion (id BINARY(16) NOT NULL, portee VARCHAR(12) NOT NULL, fiches_sources JSON NOT NULL, fiche_survivante BINARY(16) NOT NULL, champs_arbitres JSON NOT NULL, snapshot_avant JSON NOT NULL, motif VARCHAR(255) DEFAULT NULL, date_fusion DATETIME NOT NULL, statut VARCHAR(16) DEFAULT \'active\' NOT NULL, date_defusion DATETIME DEFAULT NULL, effectue_par_id BINARY(16) NOT NULL, defusionne_par_id BINARY(16) DEFAULT NULL, INDEX IDX_1299E129FAE26142 (effectue_par_id), INDEX IDX_1299E12914A4C784 (defusionne_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_mouvement_pmv (id BINARY(16) NOT NULL, type VARCHAR(24) NOT NULL, montant NUMERIC(10, 2) NOT NULL, solde_apres NUMERIC(10, 2) NOT NULL, date_mouvement DATETIME NOT NULL, canal VARCHAR(12) DEFAULT NULL, ref_vente_m2 BINARY(16) DEFAULT NULL, motif VARCHAR(255) DEFAULT NULL, pmv_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, utilisateur_id BINARY(16) DEFAULT NULL, INDEX IDX_D50ED5FC16FCFC (pmv_id), INDEX IDX_D50ED5FFF631228 (etablissement_id), INDEX IDX_D50ED5FFB88E14F (utilisateur_id), INDEX idx_mouvement_pmv_pmv_date (pmv_id, date_mouvement), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_parametre_pmv_etablissement (id BINARY(16) NOT NULL, recharge_expiree_autorisee TINYINT DEFAULT 0 NOT NULL, regle_echeance JSON NOT NULL, traitement_solde_residuel VARCHAR(24) DEFAULT \'conserve\' NOT NULL, etablissement_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_parametre_pmv_etablissement (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_pmv (id BINARY(16) NOT NULL, solde NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, devise VARCHAR(3) DEFAULT \'EUR\' NOT NULL, date_echeance DATE DEFAULT NULL, statut VARCHAR(12) DEFAULT \'expire\' NOT NULL, client_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_pmv_client (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_regle_conservation (id BINARY(16) NOT NULL, categorie_donnee VARCHAR(64) NOT NULL, duree_mois INT NOT NULL, action_echeance VARCHAR(16) NOT NULL, groupe_id BINARY(16) NOT NULL, INDEX IDX_DE088B867A45358C (groupe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE crm_beneficiaire ADD CONSTRAINT FK_8DA01A4697A77B84 FOREIGN KEY (famille_id) REFERENCES crm_famille (id)');
        $this->addSql('ALTER TABLE crm_beneficiaire ADD CONSTRAINT FK_8DA01A4619EB6921 FOREIGN KEY (client_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE crm_client ADD CONSTRAINT FK_EFFAB5947A45358C FOREIGN KEY (groupe_id) REFERENCES org_groupe (id)');
        $this->addSql('ALTER TABLE crm_client ADD CONSTRAINT FK_EFFAB59413C9E436 FOREIGN KEY (etablissement_creation_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE crm_client ADD CONSTRAINT FK_EFFAB594DBB9BC49 FOREIGN KEY (fusionne_dans_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE crm_client ADD CONSTRAINT FK_EFFAB594FC29C013 FOREIGN KEY (cree_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE crm_consentement ADD CONSTRAINT FK_7470E86A19EB6921 FOREIGN KEY (client_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE crm_demande_rgpd ADD CONSTRAINT FK_934F068819EB6921 FOREIGN KEY (client_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE crm_demande_rgpd ADD CONSTRAINT FK_934F0688167FABE8 FOREIGN KEY (traite_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE crm_famille ADD CONSTRAINT FK_C838BE847A45358C FOREIGN KEY (groupe_id) REFERENCES org_groupe (id)');
        $this->addSql('ALTER TABLE crm_famille ADD CONSTRAINT FK_C838BE84C0F84BDD FOREIGN KEY (payeur_principal_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE crm_journal_fusion ADD CONSTRAINT FK_1299E129FAE26142 FOREIGN KEY (effectue_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE crm_journal_fusion ADD CONSTRAINT FK_1299E12914A4C784 FOREIGN KEY (defusionne_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE crm_mouvement_pmv ADD CONSTRAINT FK_D50ED5FC16FCFC FOREIGN KEY (pmv_id) REFERENCES crm_pmv (id)');
        $this->addSql('ALTER TABLE crm_mouvement_pmv ADD CONSTRAINT FK_D50ED5FFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE crm_mouvement_pmv ADD CONSTRAINT FK_D50ED5FFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE crm_parametre_pmv_etablissement ADD CONSTRAINT FK_3137EA9FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE crm_pmv ADD CONSTRAINT FK_AED0207719EB6921 FOREIGN KEY (client_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE crm_regle_conservation ADD CONSTRAINT FK_DE088B867A45358C FOREIGN KEY (groupe_id) REFERENCES org_groupe (id)');
        // Invariant RG-M4-03 : le solde PMV ne peut jamais devenir négatif (garde-fou base, en plus
        // du débit atomique applicatif, §1.2/§2.2 plan-crm.md).
        $this->addSql('ALTER TABLE crm_pmv ADD CONSTRAINT chk_pmv_solde_positif CHECK (solde >= 0)');
        $this->addSql('ALTER TABLE sec_utilisateur ADD client_lie BINARY(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE crm_beneficiaire DROP FOREIGN KEY FK_8DA01A4697A77B84');
        $this->addSql('ALTER TABLE crm_beneficiaire DROP FOREIGN KEY FK_8DA01A4619EB6921');
        $this->addSql('ALTER TABLE crm_client DROP FOREIGN KEY FK_EFFAB5947A45358C');
        $this->addSql('ALTER TABLE crm_client DROP FOREIGN KEY FK_EFFAB59413C9E436');
        $this->addSql('ALTER TABLE crm_client DROP FOREIGN KEY FK_EFFAB594DBB9BC49');
        $this->addSql('ALTER TABLE crm_client DROP FOREIGN KEY FK_EFFAB594FC29C013');
        $this->addSql('ALTER TABLE crm_consentement DROP FOREIGN KEY FK_7470E86A19EB6921');
        $this->addSql('ALTER TABLE crm_demande_rgpd DROP FOREIGN KEY FK_934F068819EB6921');
        $this->addSql('ALTER TABLE crm_demande_rgpd DROP FOREIGN KEY FK_934F0688167FABE8');
        $this->addSql('ALTER TABLE crm_famille DROP FOREIGN KEY FK_C838BE847A45358C');
        $this->addSql('ALTER TABLE crm_famille DROP FOREIGN KEY FK_C838BE84C0F84BDD');
        $this->addSql('ALTER TABLE crm_journal_fusion DROP FOREIGN KEY FK_1299E129FAE26142');
        $this->addSql('ALTER TABLE crm_journal_fusion DROP FOREIGN KEY FK_1299E12914A4C784');
        $this->addSql('ALTER TABLE crm_mouvement_pmv DROP FOREIGN KEY FK_D50ED5FC16FCFC');
        $this->addSql('ALTER TABLE crm_mouvement_pmv DROP FOREIGN KEY FK_D50ED5FFF631228');
        $this->addSql('ALTER TABLE crm_mouvement_pmv DROP FOREIGN KEY FK_D50ED5FFB88E14F');
        $this->addSql('ALTER TABLE crm_parametre_pmv_etablissement DROP FOREIGN KEY FK_3137EA9FF631228');
        $this->addSql('ALTER TABLE crm_pmv DROP FOREIGN KEY FK_AED0207719EB6921');
        $this->addSql('ALTER TABLE crm_regle_conservation DROP FOREIGN KEY FK_DE088B867A45358C');
        $this->addSql('DROP TABLE crm_beneficiaire');
        $this->addSql('DROP TABLE crm_client');
        $this->addSql('DROP TABLE crm_consentement');
        $this->addSql('DROP TABLE crm_demande_rgpd');
        $this->addSql('DROP TABLE crm_famille');
        $this->addSql('DROP TABLE crm_journal_fusion');
        $this->addSql('DROP TABLE crm_mouvement_pmv');
        $this->addSql('DROP TABLE crm_parametre_pmv_etablissement');
        $this->addSql('DROP TABLE crm_pmv');
        $this->addSql('DROP TABLE crm_regle_conservation');
        $this->addSql('ALTER TABLE sec_utilisateur DROP client_lie');
    }
}
