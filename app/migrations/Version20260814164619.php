<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260814164619 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1 Offre & Tarification (L1) : tables off_* (produit, type_produit, type_tarif, saison, '
            . 'grille_tarifaire, prix_historique, tranche_qf, formule, service_inclus, carte_multi_entrees, '
            . 'promotion, categorie, stock, pool, conversion_type) + jointures et contraintes.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE off_carte_multi_entrees (id BINARY(16) NOT NULL, nb_paye INT NOT NULL, nb_credite INT NOT NULL, validite_duree VARCHAR(255) DEFAULT NULL, date_butoir DATE DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_categorie (id BINARY(16) NOT NULL, axe VARCHAR(12) NOT NULL, libelle VARCHAR(120) NOT NULL, chemin VARCHAR(255) DEFAULT NULL, parent_id BINARY(16) DEFAULT NULL, INDEX IDX_BE92C325727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_conversion_type (id BINARY(16) NOT NULL, auteur VARCHAR(180) DEFAULT NULL, date_heure DATETIME NOT NULL, mapping JSON NOT NULL, produit_id BINARY(16) NOT NULL, ancien_type_id BINARY(16) NOT NULL, nouveau_type_id BINARY(16) NOT NULL, INDEX IDX_B2CAB1F9F347EFB (produit_id), INDEX IDX_B2CAB1F93760F0B6 (ancien_type_id), INDEX IDX_B2CAB1F9BFCFEF04 (nouveau_type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_formule (id BINARY(16) NOT NULL, droit_acces JSON NOT NULL, periodicite VARCHAR(16) NOT NULL, sepa_actif TINYINT DEFAULT 0 NOT NULL, jour_prelevement SMALLINT DEFAULT NULL, renouvellement JSON NOT NULL, engagement JSON DEFAULT NULL, date_debut DATE DEFAULT NULL, date_fin DATE DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_grille_tarifaire (id BINARY(16) NOT NULL, prix NUMERIC(10, 2) DEFAULT NULL, produit_id BINARY(16) NOT NULL, type_tarif_id BINARY(16) NOT NULL, saison_id BINARY(16) NOT NULL, tranche_qf_id BINARY(16) DEFAULT NULL, INDEX IDX_40653BC9F347EFB (produit_id), INDEX IDX_40653BC9F8832DA5 (type_tarif_id), INDEX IDX_40653BC9F965414C (saison_id), INDEX IDX_40653BC9E275904D (tranche_qf_id), UNIQUE INDEX uniq_grille_triplet (produit_id, type_tarif_id, saison_id, tranche_qf_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_pool (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, disponibilite INT DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_prix_historique (id BINARY(16) NOT NULL, valeur NUMERIC(10, 2) DEFAULT NULL, date_effet DATETIME NOT NULL, auteur VARCHAR(180) DEFAULT NULL, grille_id BINARY(16) NOT NULL, INDEX IDX_CAC0E090985C2966 (grille_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_produit (id BINARY(16) NOT NULL, libelle JSON NOT NULL, libelle_recherche VARCHAR(512) DEFAULT NULL, type_code VARCHAR(48) DEFAULT NULL, code VARCHAR(64) NOT NULL, statut VARCHAR(16) DEFAULT \'brouillon\' NOT NULL, canaux JSON NOT NULL, duree_validite VARCHAR(255) DEFAULT NULL, note_interne LONGTEXT DEFAULT NULL, description JSON DEFAULT NULL, couleur_caisse VARCHAR(9) DEFAULT NULL, champs_perso JSON DEFAULT NULL, regle_pca VARCHAR(24) DEFAULT \'aucune\' NOT NULL, compte_comptable VARCHAR(32) DEFAULT NULL, taux_tva NUMERIC(5, 2) DEFAULT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, type_id BINARY(16) NOT NULL, formule_id BINARY(16) DEFAULT NULL, carte_id BINARY(16) DEFAULT NULL, stock_id BINARY(16) DEFAULT NULL, INDEX IDX_63E48D38C54C8C93 (type_id), UNIQUE INDEX UNIQ_63E48D382A68F4D1 (formule_id), UNIQUE INDEX UNIQ_63E48D38C9C7CEB6 (carte_id), INDEX IDX_63E48D38DCD6110 (stock_id), UNIQUE INDEX uniq_produit_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_produit_etablissement (produit_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_72C1CE47F347EFB (produit_id), INDEX IDX_72C1CE47FF631228 (etablissement_id), PRIMARY KEY (produit_id, etablissement_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_produit_categorie (produit_id BINARY(16) NOT NULL, categorie_id BINARY(16) NOT NULL, INDEX IDX_551B2F1EF347EFB (produit_id), INDEX IDX_551B2F1EBCF5E72D (categorie_id), PRIMARY KEY (produit_id, categorie_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_produit_associe (produit_source BINARY(16) NOT NULL, produit_target BINARY(16) NOT NULL, INDEX IDX_BBBCDCC4A8D8B449 (produit_source), INDEX IDX_BBBCDCC4B13DE4C6 (produit_target), PRIMARY KEY (produit_source, produit_target)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_promotion (id BINARY(16) NOT NULL, nom VARCHAR(120) NOT NULL, type VARCHAR(24) NOT NULL, valeur NUMERIC(10, 2) DEFAULT NULL, conditions LONGTEXT DEFAULT NULL, date_debut DATE DEFAULT NULL, date_fin DATE DEFAULT NULL, cumul VARCHAR(12) DEFAULT \'cumulable\' NOT NULL, canaux JSON DEFAULT NULL, eligibilite JSON DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_saison (id BINARY(16) NOT NULL, nom VARCHAR(120) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, priorite INT DEFAULT 0 NOT NULL, recurrence_annuelle TINYINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_service_inclus (id BINARY(16) NOT NULL, activite_ref BINARY(16) NOT NULL, quota INT NOT NULL, periode VARCHAR(24) DEFAULT \'semaine_calendaire\' NOT NULL, formule_id BINARY(16) NOT NULL, INDEX IDX_B6E20DCF2A68F4D1 (formule_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_stock (id BINARY(16) NOT NULL, type VARCHAR(12) DEFAULT \'dedie\' NOT NULL, disponibilite INT DEFAULT 0 NOT NULL, pool_id BINARY(16) DEFAULT NULL, INDEX IDX_EBFD91677B3406DF (pool_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_tranche_qf (id BINARY(16) NOT NULL, borne_min NUMERIC(10, 2) NOT NULL, borne_max NUMERIC(10, 2) NOT NULL, type_tarif_id BINARY(16) NOT NULL, INDEX IDX_B13D5DA3F8832DA5 (type_tarif_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_type_produit (id BINARY(16) NOT NULL, code VARCHAR(48) NOT NULL, libelle VARCHAR(120) NOT NULL, facettes JSON NOT NULL, defauts JSON DEFAULT NULL, UNIQUE INDEX uniq_type_produit_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_type_produit_compatible (source_id BINARY(16) NOT NULL, cible_id BINARY(16) NOT NULL, INDEX IDX_CD241402953C1C61 (source_id), INDEX IDX_CD241402A96E5E09 (cible_id), PRIMARY KEY (source_id, cible_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE off_type_tarif (id BINARY(16) NOT NULL, nom VARCHAR(120) NOT NULL, visibilite_canal JSON NOT NULL, ordre_affichage INT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE off_categorie ADD CONSTRAINT FK_BE92C325727ACA70 FOREIGN KEY (parent_id) REFERENCES off_categorie (id)');
        $this->addSql('ALTER TABLE off_conversion_type ADD CONSTRAINT FK_B2CAB1F9F347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id)');
        $this->addSql('ALTER TABLE off_conversion_type ADD CONSTRAINT FK_B2CAB1F93760F0B6 FOREIGN KEY (ancien_type_id) REFERENCES off_type_produit (id)');
        $this->addSql('ALTER TABLE off_conversion_type ADD CONSTRAINT FK_B2CAB1F9BFCFEF04 FOREIGN KEY (nouveau_type_id) REFERENCES off_type_produit (id)');
        $this->addSql('ALTER TABLE off_grille_tarifaire ADD CONSTRAINT FK_40653BC9F347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id)');
        $this->addSql('ALTER TABLE off_grille_tarifaire ADD CONSTRAINT FK_40653BC9F8832DA5 FOREIGN KEY (type_tarif_id) REFERENCES off_type_tarif (id)');
        $this->addSql('ALTER TABLE off_grille_tarifaire ADD CONSTRAINT FK_40653BC9F965414C FOREIGN KEY (saison_id) REFERENCES off_saison (id)');
        $this->addSql('ALTER TABLE off_grille_tarifaire ADD CONSTRAINT FK_40653BC9E275904D FOREIGN KEY (tranche_qf_id) REFERENCES off_tranche_qf (id)');
        $this->addSql('ALTER TABLE off_prix_historique ADD CONSTRAINT FK_CAC0E090985C2966 FOREIGN KEY (grille_id) REFERENCES off_grille_tarifaire (id)');
        $this->addSql('ALTER TABLE off_produit ADD CONSTRAINT FK_63E48D38C54C8C93 FOREIGN KEY (type_id) REFERENCES off_type_produit (id)');
        $this->addSql('ALTER TABLE off_produit ADD CONSTRAINT FK_63E48D382A68F4D1 FOREIGN KEY (formule_id) REFERENCES off_formule (id)');
        $this->addSql('ALTER TABLE off_produit ADD CONSTRAINT FK_63E48D38C9C7CEB6 FOREIGN KEY (carte_id) REFERENCES off_carte_multi_entrees (id)');
        $this->addSql('ALTER TABLE off_produit ADD CONSTRAINT FK_63E48D38DCD6110 FOREIGN KEY (stock_id) REFERENCES off_stock (id)');
        $this->addSql('ALTER TABLE off_produit_etablissement ADD CONSTRAINT FK_72C1CE47F347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE off_produit_etablissement ADD CONSTRAINT FK_72C1CE47FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE off_produit_categorie ADD CONSTRAINT FK_551B2F1EF347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE off_produit_categorie ADD CONSTRAINT FK_551B2F1EBCF5E72D FOREIGN KEY (categorie_id) REFERENCES off_categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE off_produit_associe ADD CONSTRAINT FK_BBBCDCC4A8D8B449 FOREIGN KEY (produit_source) REFERENCES off_produit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE off_produit_associe ADD CONSTRAINT FK_BBBCDCC4B13DE4C6 FOREIGN KEY (produit_target) REFERENCES off_produit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE off_service_inclus ADD CONSTRAINT FK_B6E20DCF2A68F4D1 FOREIGN KEY (formule_id) REFERENCES off_formule (id)');
        $this->addSql('ALTER TABLE off_stock ADD CONSTRAINT FK_EBFD91677B3406DF FOREIGN KEY (pool_id) REFERENCES off_pool (id)');
        $this->addSql('ALTER TABLE off_tranche_qf ADD CONSTRAINT FK_B13D5DA3F8832DA5 FOREIGN KEY (type_tarif_id) REFERENCES off_type_tarif (id)');
        $this->addSql('ALTER TABLE off_type_produit_compatible ADD CONSTRAINT FK_CD241402953C1C61 FOREIGN KEY (source_id) REFERENCES off_type_produit (id)');
        $this->addSql('ALTER TABLE off_type_produit_compatible ADD CONSTRAINT FK_CD241402A96E5E09 FOREIGN KEY (cible_id) REFERENCES off_type_produit (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE off_categorie DROP FOREIGN KEY FK_BE92C325727ACA70');
        $this->addSql('ALTER TABLE off_conversion_type DROP FOREIGN KEY FK_B2CAB1F9F347EFB');
        $this->addSql('ALTER TABLE off_conversion_type DROP FOREIGN KEY FK_B2CAB1F93760F0B6');
        $this->addSql('ALTER TABLE off_conversion_type DROP FOREIGN KEY FK_B2CAB1F9BFCFEF04');
        $this->addSql('ALTER TABLE off_grille_tarifaire DROP FOREIGN KEY FK_40653BC9F347EFB');
        $this->addSql('ALTER TABLE off_grille_tarifaire DROP FOREIGN KEY FK_40653BC9F8832DA5');
        $this->addSql('ALTER TABLE off_grille_tarifaire DROP FOREIGN KEY FK_40653BC9F965414C');
        $this->addSql('ALTER TABLE off_grille_tarifaire DROP FOREIGN KEY FK_40653BC9E275904D');
        $this->addSql('ALTER TABLE off_prix_historique DROP FOREIGN KEY FK_CAC0E090985C2966');
        $this->addSql('ALTER TABLE off_produit DROP FOREIGN KEY FK_63E48D38C54C8C93');
        $this->addSql('ALTER TABLE off_produit DROP FOREIGN KEY FK_63E48D382A68F4D1');
        $this->addSql('ALTER TABLE off_produit DROP FOREIGN KEY FK_63E48D38C9C7CEB6');
        $this->addSql('ALTER TABLE off_produit DROP FOREIGN KEY FK_63E48D38DCD6110');
        $this->addSql('ALTER TABLE off_produit_etablissement DROP FOREIGN KEY FK_72C1CE47F347EFB');
        $this->addSql('ALTER TABLE off_produit_etablissement DROP FOREIGN KEY FK_72C1CE47FF631228');
        $this->addSql('ALTER TABLE off_produit_categorie DROP FOREIGN KEY FK_551B2F1EF347EFB');
        $this->addSql('ALTER TABLE off_produit_categorie DROP FOREIGN KEY FK_551B2F1EBCF5E72D');
        $this->addSql('ALTER TABLE off_produit_associe DROP FOREIGN KEY FK_BBBCDCC4A8D8B449');
        $this->addSql('ALTER TABLE off_produit_associe DROP FOREIGN KEY FK_BBBCDCC4B13DE4C6');
        $this->addSql('ALTER TABLE off_service_inclus DROP FOREIGN KEY FK_B6E20DCF2A68F4D1');
        $this->addSql('ALTER TABLE off_stock DROP FOREIGN KEY FK_EBFD91677B3406DF');
        $this->addSql('ALTER TABLE off_tranche_qf DROP FOREIGN KEY FK_B13D5DA3F8832DA5');
        $this->addSql('ALTER TABLE off_type_produit_compatible DROP FOREIGN KEY FK_CD241402953C1C61');
        $this->addSql('ALTER TABLE off_type_produit_compatible DROP FOREIGN KEY FK_CD241402A96E5E09');
        $this->addSql('DROP TABLE off_carte_multi_entrees');
        $this->addSql('DROP TABLE off_categorie');
        $this->addSql('DROP TABLE off_conversion_type');
        $this->addSql('DROP TABLE off_formule');
        $this->addSql('DROP TABLE off_grille_tarifaire');
        $this->addSql('DROP TABLE off_pool');
        $this->addSql('DROP TABLE off_prix_historique');
        $this->addSql('DROP TABLE off_produit');
        $this->addSql('DROP TABLE off_produit_etablissement');
        $this->addSql('DROP TABLE off_produit_categorie');
        $this->addSql('DROP TABLE off_produit_associe');
        $this->addSql('DROP TABLE off_promotion');
        $this->addSql('DROP TABLE off_saison');
        $this->addSql('DROP TABLE off_service_inclus');
        $this->addSql('DROP TABLE off_stock');
        $this->addSql('DROP TABLE off_tranche_qf');
        $this->addSql('DROP TABLE off_type_produit');
        $this->addSql('DROP TABLE off_type_produit_compatible');
        $this->addSql('DROP TABLE off_type_tarif');
    }
}
