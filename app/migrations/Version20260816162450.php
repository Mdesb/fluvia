<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module M7 Reporting & pilotage multi-niveaux (L11) — migration structurelle (§5.1 plan-reporting.md) :
 * `report_axe_analytique`, `report_indicateur`, `report_objectif_indicateur`, `report_mesure`
 * (table de faits, `cle_agregation` unique, index composites indicateur×niveau×{établissement,région,
 * groupe}×période), `report_tableau_de_bord` (+ table de jointure indicateurs), `report_rapport_planifie`,
 * `report_destinataire_rapport`, `report_export`. Générée par `doctrine:migrations:diff` à partir des
 * entités `App\Reporting\Entity\*`.
 */
final class Version20260816162450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M7 Reporting (L11) : schéma report_axe_analytique/indicateur/mesure/objectif_indicateur/tableau_de_bord/rapport_planifie/destinataire_rapport/export.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE report_axe_analytique (id BINARY(16) NOT NULL, code VARCHAR(30) NOT NULL, libelle VARCHAR(80) NOT NULL, type VARCHAR(20) NOT NULL, granularites JSON DEFAULT NULL, est_extension TINYINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_axe_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report_destinataire_rapport (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, niveau VARCHAR(20) NOT NULL, rapport_planifie_id BINARY(16) NOT NULL, etablissement_id BINARY(16) DEFAULT NULL, region_id BINARY(16) DEFAULT NULL, groupe_id BINARY(16) DEFAULT NULL, INDEX IDX_35A05DFC36F535B7 (rapport_planifie_id), INDEX IDX_35A05DFCFF631228 (etablissement_id), INDEX IDX_35A05DFC98260155 (region_id), INDEX IDX_35A05DFC7A45358C (groupe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report_export (id BINARY(16) NOT NULL, destinataire_email VARCHAR(180) DEFAULT NULL, format VARCHAR(4) NOT NULL, axes_appliques JSON DEFAULT NULL, statut VARCHAR(8) NOT NULL, chemin_stockage VARCHAR(255) DEFAULT NULL, message_erreur VARCHAR(255) DEFAULT NULL, genere_le DATETIME NOT NULL, envoye_le DATETIME DEFAULT NULL, niveau VARCHAR(20) NOT NULL, rapport_planifie_id BINARY(16) DEFAULT NULL, demande_par_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) DEFAULT NULL, region_id BINARY(16) DEFAULT NULL, groupe_id BINARY(16) DEFAULT NULL, INDEX IDX_6B7FC30B36F535B7 (rapport_planifie_id), INDEX IDX_6B7FC30B4C0C045 (demande_par_id), INDEX IDX_6B7FC30BFF631228 (etablissement_id), INDEX IDX_6B7FC30B98260155 (region_id), INDEX IDX_6B7FC30B7A45358C (groupe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report_indicateur (id BINARY(16) NOT NULL, code VARCHAR(40) NOT NULL, libelle VARCHAR(120) NOT NULL, unite VARCHAR(20) NOT NULL, mode_calcul VARCHAR(12) NOT NULL, nature VARCHAR(12) NOT NULL, source_module VARCHAR(16) NOT NULL, seuil_completude_minutes INT DEFAULT 60, actif TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_indicateur_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report_mesure (id BINARY(16) NOT NULL, activite VARCHAR(60) DEFAULT NULL, produit BINARY(16) DEFAULT NULL, categorie BINARY(16) DEFAULT NULL, canal VARCHAR(20) DEFAULT NULL, periode_debut DATE NOT NULL, periode_fin DATE NOT NULL, granularite VARCHAR(8) NOT NULL, valeur NUMERIC(14, 2) NOT NULL, regime_exploitant VARCHAR(14) DEFAULT NULL, comparabilite_regime TINYINT DEFAULT 0 NOT NULL, statut_completude VARCHAR(8) DEFAULT \'complet\' NOT NULL, sites_manquants JSON DEFAULT NULL, fuseau_reference VARCHAR(40) DEFAULT \'Europe/Paris\' NOT NULL, devise VARCHAR(3) DEFAULT \'EUR\' NOT NULL, cle_agregation VARCHAR(64) NOT NULL, genere_le DATETIME NOT NULL, niveau VARCHAR(20) NOT NULL, indicateur_id BINARY(16) NOT NULL, etablissement_id BINARY(16) DEFAULT NULL, region_id BINARY(16) DEFAULT NULL, groupe_id BINARY(16) DEFAULT NULL, INDEX IDX_76E8BBEFDA3B8F3D (indicateur_id), INDEX IDX_76E8BBEFFF631228 (etablissement_id), INDEX IDX_76E8BBEF98260155 (region_id), INDEX IDX_76E8BBEF7A45358C (groupe_id), INDEX idx_mesure_etablissement (indicateur_id, niveau, etablissement_id, periode_debut, periode_fin, granularite), INDEX idx_mesure_region (indicateur_id, niveau, region_id, periode_debut, periode_fin, granularite), INDEX idx_mesure_groupe (indicateur_id, niveau, groupe_id, periode_debut, periode_fin, granularite), UNIQUE INDEX uniq_mesure_cle_agregation (cle_agregation), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report_objectif_indicateur (id BINARY(16) NOT NULL, periode_debut DATE NOT NULL, periode_fin DATE NOT NULL, granularite VARCHAR(8) NOT NULL, valeur_cible NUMERIC(14, 2) NOT NULL, niveau VARCHAR(20) NOT NULL, indicateur_id BINARY(16) NOT NULL, etablissement_id BINARY(16) DEFAULT NULL, region_id BINARY(16) DEFAULT NULL, groupe_id BINARY(16) DEFAULT NULL, INDEX IDX_DC9DF2D2DA3B8F3D (indicateur_id), INDEX IDX_DC9DF2D2FF631228 (etablissement_id), INDEX IDX_DC9DF2D298260155 (region_id), INDEX IDX_DC9DF2D27A45358C (groupe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report_rapport_planifie (id BINARY(16) NOT NULL, nom VARCHAR(160) NOT NULL, format VARCHAR(4) NOT NULL, periodicite VARCHAR(12) NOT NULL, heure_envoi VARCHAR(5) NOT NULL, etat VARCHAR(9) DEFAULT \'actif\' NOT NULL, dernier_envoi DATETIME DEFAULT NULL, prochain_envoi DATETIME DEFAULT NULL, tableau_de_bord_id BINARY(16) NOT NULL, createur_id BINARY(16) NOT NULL, INDEX IDX_726C6FEF6D25C725 (tableau_de_bord_id), INDEX IDX_726C6FEF73A201E5 (createur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report_tableau_de_bord (id BINARY(16) NOT NULL, nom VARCHAR(160) NOT NULL, mise_en_page JSON DEFAULT NULL, actif TINYINT DEFAULT 1 NOT NULL, niveau VARCHAR(20) NOT NULL, etablissement_id BINARY(16) DEFAULT NULL, region_id BINARY(16) DEFAULT NULL, groupe_id BINARY(16) DEFAULT NULL, INDEX IDX_16578CA7FF631228 (etablissement_id), INDEX IDX_16578CA798260155 (region_id), INDEX IDX_16578CA77A45358C (groupe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report_tableau_de_bord_indicateur (tableau_de_bord_id BINARY(16) NOT NULL, indicateur_id BINARY(16) NOT NULL, INDEX IDX_97B598086D25C725 (tableau_de_bord_id), INDEX IDX_97B59808DA3B8F3D (indicateur_id), PRIMARY KEY (tableau_de_bord_id, indicateur_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE report_destinataire_rapport ADD CONSTRAINT FK_35A05DFC36F535B7 FOREIGN KEY (rapport_planifie_id) REFERENCES report_rapport_planifie (id)');
        $this->addSql('ALTER TABLE report_destinataire_rapport ADD CONSTRAINT FK_35A05DFCFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE report_destinataire_rapport ADD CONSTRAINT FK_35A05DFC98260155 FOREIGN KEY (region_id) REFERENCES org_region (id)');
        $this->addSql('ALTER TABLE report_destinataire_rapport ADD CONSTRAINT FK_35A05DFC7A45358C FOREIGN KEY (groupe_id) REFERENCES org_groupe (id)');
        $this->addSql('ALTER TABLE report_export ADD CONSTRAINT FK_6B7FC30B36F535B7 FOREIGN KEY (rapport_planifie_id) REFERENCES report_rapport_planifie (id)');
        $this->addSql('ALTER TABLE report_export ADD CONSTRAINT FK_6B7FC30B4C0C045 FOREIGN KEY (demande_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE report_export ADD CONSTRAINT FK_6B7FC30BFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE report_export ADD CONSTRAINT FK_6B7FC30B98260155 FOREIGN KEY (region_id) REFERENCES org_region (id)');
        $this->addSql('ALTER TABLE report_export ADD CONSTRAINT FK_6B7FC30B7A45358C FOREIGN KEY (groupe_id) REFERENCES org_groupe (id)');
        $this->addSql('ALTER TABLE report_mesure ADD CONSTRAINT FK_76E8BBEFDA3B8F3D FOREIGN KEY (indicateur_id) REFERENCES report_indicateur (id)');
        $this->addSql('ALTER TABLE report_mesure ADD CONSTRAINT FK_76E8BBEFFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE report_mesure ADD CONSTRAINT FK_76E8BBEF98260155 FOREIGN KEY (region_id) REFERENCES org_region (id)');
        $this->addSql('ALTER TABLE report_mesure ADD CONSTRAINT FK_76E8BBEF7A45358C FOREIGN KEY (groupe_id) REFERENCES org_groupe (id)');
        $this->addSql('ALTER TABLE report_objectif_indicateur ADD CONSTRAINT FK_DC9DF2D2DA3B8F3D FOREIGN KEY (indicateur_id) REFERENCES report_indicateur (id)');
        $this->addSql('ALTER TABLE report_objectif_indicateur ADD CONSTRAINT FK_DC9DF2D2FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE report_objectif_indicateur ADD CONSTRAINT FK_DC9DF2D298260155 FOREIGN KEY (region_id) REFERENCES org_region (id)');
        $this->addSql('ALTER TABLE report_objectif_indicateur ADD CONSTRAINT FK_DC9DF2D27A45358C FOREIGN KEY (groupe_id) REFERENCES org_groupe (id)');
        $this->addSql('ALTER TABLE report_rapport_planifie ADD CONSTRAINT FK_726C6FEF6D25C725 FOREIGN KEY (tableau_de_bord_id) REFERENCES report_tableau_de_bord (id)');
        $this->addSql('ALTER TABLE report_rapport_planifie ADD CONSTRAINT FK_726C6FEF73A201E5 FOREIGN KEY (createur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE report_tableau_de_bord ADD CONSTRAINT FK_16578CA7FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE report_tableau_de_bord ADD CONSTRAINT FK_16578CA798260155 FOREIGN KEY (region_id) REFERENCES org_region (id)');
        $this->addSql('ALTER TABLE report_tableau_de_bord ADD CONSTRAINT FK_16578CA77A45358C FOREIGN KEY (groupe_id) REFERENCES org_groupe (id)');
        $this->addSql('ALTER TABLE report_tableau_de_bord_indicateur ADD CONSTRAINT FK_97B598086D25C725 FOREIGN KEY (tableau_de_bord_id) REFERENCES report_tableau_de_bord (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE report_tableau_de_bord_indicateur ADD CONSTRAINT FK_97B59808DA3B8F3D FOREIGN KEY (indicateur_id) REFERENCES report_indicateur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE report_destinataire_rapport DROP FOREIGN KEY FK_35A05DFC36F535B7');
        $this->addSql('ALTER TABLE report_destinataire_rapport DROP FOREIGN KEY FK_35A05DFCFF631228');
        $this->addSql('ALTER TABLE report_destinataire_rapport DROP FOREIGN KEY FK_35A05DFC98260155');
        $this->addSql('ALTER TABLE report_destinataire_rapport DROP FOREIGN KEY FK_35A05DFC7A45358C');
        $this->addSql('ALTER TABLE report_export DROP FOREIGN KEY FK_6B7FC30B36F535B7');
        $this->addSql('ALTER TABLE report_export DROP FOREIGN KEY FK_6B7FC30B4C0C045');
        $this->addSql('ALTER TABLE report_export DROP FOREIGN KEY FK_6B7FC30BFF631228');
        $this->addSql('ALTER TABLE report_export DROP FOREIGN KEY FK_6B7FC30B98260155');
        $this->addSql('ALTER TABLE report_export DROP FOREIGN KEY FK_6B7FC30B7A45358C');
        $this->addSql('ALTER TABLE report_mesure DROP FOREIGN KEY FK_76E8BBEFDA3B8F3D');
        $this->addSql('ALTER TABLE report_mesure DROP FOREIGN KEY FK_76E8BBEFFF631228');
        $this->addSql('ALTER TABLE report_mesure DROP FOREIGN KEY FK_76E8BBEF98260155');
        $this->addSql('ALTER TABLE report_mesure DROP FOREIGN KEY FK_76E8BBEF7A45358C');
        $this->addSql('ALTER TABLE report_objectif_indicateur DROP FOREIGN KEY FK_DC9DF2D2DA3B8F3D');
        $this->addSql('ALTER TABLE report_objectif_indicateur DROP FOREIGN KEY FK_DC9DF2D2FF631228');
        $this->addSql('ALTER TABLE report_objectif_indicateur DROP FOREIGN KEY FK_DC9DF2D298260155');
        $this->addSql('ALTER TABLE report_objectif_indicateur DROP FOREIGN KEY FK_DC9DF2D27A45358C');
        $this->addSql('ALTER TABLE report_rapport_planifie DROP FOREIGN KEY FK_726C6FEF6D25C725');
        $this->addSql('ALTER TABLE report_rapport_planifie DROP FOREIGN KEY FK_726C6FEF73A201E5');
        $this->addSql('ALTER TABLE report_tableau_de_bord DROP FOREIGN KEY FK_16578CA7FF631228');
        $this->addSql('ALTER TABLE report_tableau_de_bord DROP FOREIGN KEY FK_16578CA798260155');
        $this->addSql('ALTER TABLE report_tableau_de_bord DROP FOREIGN KEY FK_16578CA77A45358C');
        $this->addSql('ALTER TABLE report_tableau_de_bord_indicateur DROP FOREIGN KEY FK_97B598086D25C725');
        $this->addSql('ALTER TABLE report_tableau_de_bord_indicateur DROP FOREIGN KEY FK_97B59808DA3B8F3D');
        $this->addSql('DROP TABLE report_axe_analytique');
        $this->addSql('DROP TABLE report_destinataire_rapport');
        $this->addSql('DROP TABLE report_export');
        $this->addSql('DROP TABLE report_indicateur');
        $this->addSql('DROP TABLE report_mesure');
        $this->addSql('DROP TABLE report_objectif_indicateur');
        $this->addSql('DROP TABLE report_rapport_planifie');
        $this->addSql('DROP TABLE report_tableau_de_bord');
        $this->addSql('DROP TABLE report_tableau_de_bord_indicateur');
    }
}
