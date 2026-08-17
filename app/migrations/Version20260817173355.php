<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Stock & Inventaire boutique (plan-stock.md §6) : les 14 tables `App\Stock` (fournisseurs,
 * catalogue fournisseur, article de stock EAN, paramétrage, commande/réception d'achat, couches de
 * coût `stk_lot`, journal append-only `stk_mouvement`/`stk_imputation_lot`, transferts, inventaires).
 * CHECK métier ajoutés manuellement (§6 du plan) : `stk_lot.quantite_restante <= quantite_initiale`,
 * `stk_article.seuil_min <= seuil_max`. Suppose les migrations socle L0 + M1 (off_produit) + M2
 * (sec_utilisateur) jouées d'abord.
 */
final class Version20260817173355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Stock & Inventaire boutique (plan-stock.md) : 14 tables stk_* + CHECK métier.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE stk_article (id BINARY(16) NOT NULL, code_ean VARCHAR(13) NOT NULL, libelle VARCHAR(180) NOT NULL, unite VARCHAR(10) NOT NULL, prix_achat_ht NUMERIC(12, 4) NOT NULL, taux_tva_achat NUMERIC(5, 2) NOT NULL, methode_valorisation VARCHAR(4) DEFAULT NULL, seuil_min NUMERIC(12, 3) DEFAULT \'0.000\' NOT NULL, seuil_max NUMERIC(12, 3) DEFAULT \'0.000\' NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, etablissement_id BINARY(16) NOT NULL, produit_id BINARY(16) DEFAULT NULL, INDEX IDX_3E838FF7FF631228 (etablissement_id), UNIQUE INDEX uniq_article_ean_etab (etablissement_id, code_ean), UNIQUE INDEX uniq_article_produit (produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_catalogue_fournisseur (id BINARY(16) NOT NULL, reference_fournisseur VARCHAR(64) DEFAULT NULL, prix_achat_negocie NUMERIC(12, 4) NOT NULL, delai_livraison_jours SMALLINT NOT NULL, principal TINYINT DEFAULT 0 NOT NULL, fournisseur_id BINARY(16) NOT NULL, article_stock_id BINARY(16) NOT NULL, INDEX IDX_A2E5D766670C757F (fournisseur_id), INDEX IDX_A2E5D7665825957B (article_stock_id), UNIQUE INDEX uniq_catalogue_fournisseur_article (fournisseur_id, article_stock_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_commande_achat (id BINARY(16) NOT NULL, numero VARCHAR(32) NOT NULL, statut VARCHAR(20) NOT NULL, date_commande DATE NOT NULL, date_livraison_prevue DATE DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, fournisseur_id BINARY(16) NOT NULL, INDEX IDX_24B8CC1CFF631228 (etablissement_id), INDEX IDX_24B8CC1C670C757F (fournisseur_id), UNIQUE INDEX uniq_commande_achat_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_fournisseur (id BINARY(16) NOT NULL, raison_sociale VARCHAR(180) NOT NULL, siret VARCHAR(14) DEFAULT NULL, contact VARCHAR(120) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, adresse LONGTEXT DEFAULT NULL, conditions_paiement LONGTEXT DEFAULT NULL, actif TINYINT DEFAULT 1 NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_C9FD5432FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_imputation_lot (id BINARY(16) NOT NULL, quantite_imputee NUMERIC(12, 3) NOT NULL, cout_unitaire NUMERIC(12, 4) NOT NULL, mouvement_stock_id BINARY(16) NOT NULL, lot_stock_id BINARY(16) NOT NULL, INDEX IDX_1029E6458ED12402 (mouvement_stock_id), INDEX IDX_1029E645AF079024 (lot_stock_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_inventaire (id BINARY(16) NOT NULL, perimetre VARCHAR(10) NOT NULL, filtre JSON DEFAULT NULL, statut VARCHAR(14) NOT NULL, date_lancement DATETIME NOT NULL, date_cloture DATETIME DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_5F7D5039FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_ligne_commande_achat (id BINARY(16) NOT NULL, quantite_commandee NUMERIC(12, 3) NOT NULL, prix_achat_unitaire_ht NUMERIC(12, 4) NOT NULL, taux_tva NUMERIC(5, 2) NOT NULL, quantite_recue NUMERIC(12, 3) DEFAULT \'0.000\' NOT NULL, commande_achat_id BINARY(16) NOT NULL, article_stock_id BINARY(16) NOT NULL, INDEX IDX_41EF322D28B5C98D (commande_achat_id), INDEX IDX_41EF322D5825957B (article_stock_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_ligne_inventaire (id BINARY(16) NOT NULL, quantite_theorique NUMERIC(12, 3) NOT NULL, quantite_comptee NUMERIC(12, 3) DEFAULT NULL, ecart NUMERIC(12, 3) DEFAULT NULL, significatif TINYINT DEFAULT 0 NOT NULL, date_validation DATETIME DEFAULT NULL, inventaire_id BINARY(16) NOT NULL, article_stock_id BINARY(16) NOT NULL, valide_par_utilisateur_id BINARY(16) DEFAULT NULL, mouvement_regularisation_id BINARY(16) DEFAULT NULL, INDEX IDX_540FD061CE430A85 (inventaire_id), INDEX IDX_540FD0615825957B (article_stock_id), INDEX IDX_540FD0619E1BD977 (valide_par_utilisateur_id), UNIQUE INDEX uniq_ligne_inventaire_mouvement (mouvement_regularisation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_ligne_reception_achat (id BINARY(16) NOT NULL, quantite_recue NUMERIC(12, 3) NOT NULL, prix_achat_unitaire_ht NUMERIC(12, 4) NOT NULL, reception_id BINARY(16) NOT NULL, article_stock_id BINARY(16) NOT NULL, ligne_commande_achat_id BINARY(16) DEFAULT NULL, lot_cree_id BINARY(16) DEFAULT NULL, INDEX IDX_890A3DE97C14DF52 (reception_id), INDEX IDX_890A3DE95825957B (article_stock_id), INDEX IDX_890A3DE973EFD230 (ligne_commande_achat_id), UNIQUE INDEX uniq_ligne_reception_lot (lot_cree_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_lot (id BINARY(16) NOT NULL, date_entree DATETIME NOT NULL, quantite_initiale NUMERIC(12, 3) NOT NULL, quantite_restante NUMERIC(12, 3) NOT NULL, cout_unitaire_ht NUMERIC(12, 4) NOT NULL, origine VARCHAR(24) NOT NULL, reference_origine_type VARCHAR(32) DEFAULT NULL, reference_origine_id BINARY(16) DEFAULT NULL, article_stock_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_5913F3BE5825957B (article_stock_id), INDEX IDX_5913F3BEFF631228 (etablissement_id), INDEX idx_lot_article_date (article_stock_id, date_entree), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_mouvement (id BINARY(16) NOT NULL, type VARCHAR(24) NOT NULL, date DATETIME NOT NULL, quantite NUMERIC(12, 3) NOT NULL, cout_unitaire_calcule NUMERIC(12, 4) DEFAULT NULL, cout_total_calcule NUMERIC(12, 2) DEFAULT NULL, motif VARCHAR(255) DEFAULT NULL, reference_type VARCHAR(32) DEFAULT NULL, reference_id BINARY(16) DEFAULT NULL, article_stock_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, auteur_id BINARY(16) DEFAULT NULL, INDEX IDX_A9B4C86A5825957B (article_stock_id), INDEX IDX_A9B4C86AFF631228 (etablissement_id), INDEX IDX_A9B4C86A60BB6FE6 (auteur_id), INDEX idx_mouvement_article_date (article_stock_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_parametrage (id BINARY(16) NOT NULL, methode_valorisation_defaut VARCHAR(4) NOT NULL, autoriser_stock_negatif TINYINT DEFAULT 0 NOT NULL, seuil_ecart_significatif_pourcentage NUMERIC(5, 2) DEFAULT NULL, seuil_ecart_significatif_montant NUMERIC(12, 2) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_parametrage_etablissement (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_reception_achat (id BINARY(16) NOT NULL, date DATE NOT NULL, numero_bon_livraison VARCHAR(64) NOT NULL, statut VARCHAR(10) NOT NULL, commande_achat_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, fournisseur_id BINARY(16) NOT NULL, INDEX IDX_D8B16A2D28B5C98D (commande_achat_id), INDEX IDX_D8B16A2DFF631228 (etablissement_id), INDEX IDX_D8B16A2D670C757F (fournisseur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stk_transfert (id BINARY(16) NOT NULL, quantite NUMERIC(12, 3) NOT NULL, statut VARCHAR(10) NOT NULL, date_demande DATETIME NOT NULL, date_expedition DATETIME DEFAULT NULL, date_reception DATETIME DEFAULT NULL, article_stock_source_id BINARY(16) NOT NULL, article_stock_destination_id BINARY(16) NOT NULL, mouvement_sortie_id BINARY(16) DEFAULT NULL, mouvement_entree_id BINARY(16) DEFAULT NULL, demande_par_id BINARY(16) DEFAULT NULL, INDEX IDX_ECAB98EFEE64CA17 (article_stock_source_id), INDEX IDX_ECAB98EF5AA54D5F (article_stock_destination_id), INDEX IDX_ECAB98EF3F48B45E (mouvement_sortie_id), INDEX IDX_ECAB98EF5C41B41D (mouvement_entree_id), INDEX IDX_ECAB98EF4C0C045 (demande_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE stk_article ADD CONSTRAINT FK_3E838FF7FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE stk_article ADD CONSTRAINT FK_3E838FF7F347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id)');
        $this->addSql('ALTER TABLE stk_catalogue_fournisseur ADD CONSTRAINT FK_A2E5D766670C757F FOREIGN KEY (fournisseur_id) REFERENCES stk_fournisseur (id)');
        $this->addSql('ALTER TABLE stk_catalogue_fournisseur ADD CONSTRAINT FK_A2E5D7665825957B FOREIGN KEY (article_stock_id) REFERENCES stk_article (id)');
        $this->addSql('ALTER TABLE stk_commande_achat ADD CONSTRAINT FK_24B8CC1CFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE stk_commande_achat ADD CONSTRAINT FK_24B8CC1C670C757F FOREIGN KEY (fournisseur_id) REFERENCES stk_fournisseur (id)');
        $this->addSql('ALTER TABLE stk_fournisseur ADD CONSTRAINT FK_C9FD5432FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE stk_imputation_lot ADD CONSTRAINT FK_1029E6458ED12402 FOREIGN KEY (mouvement_stock_id) REFERENCES stk_mouvement (id)');
        $this->addSql('ALTER TABLE stk_imputation_lot ADD CONSTRAINT FK_1029E645AF079024 FOREIGN KEY (lot_stock_id) REFERENCES stk_lot (id)');
        $this->addSql('ALTER TABLE stk_inventaire ADD CONSTRAINT FK_5F7D5039FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE stk_ligne_commande_achat ADD CONSTRAINT FK_41EF322D28B5C98D FOREIGN KEY (commande_achat_id) REFERENCES stk_commande_achat (id)');
        $this->addSql('ALTER TABLE stk_ligne_commande_achat ADD CONSTRAINT FK_41EF322D5825957B FOREIGN KEY (article_stock_id) REFERENCES stk_article (id)');
        $this->addSql('ALTER TABLE stk_ligne_inventaire ADD CONSTRAINT FK_540FD061CE430A85 FOREIGN KEY (inventaire_id) REFERENCES stk_inventaire (id)');
        $this->addSql('ALTER TABLE stk_ligne_inventaire ADD CONSTRAINT FK_540FD0615825957B FOREIGN KEY (article_stock_id) REFERENCES stk_article (id)');
        $this->addSql('ALTER TABLE stk_ligne_inventaire ADD CONSTRAINT FK_540FD0619E1BD977 FOREIGN KEY (valide_par_utilisateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE stk_ligne_inventaire ADD CONSTRAINT FK_540FD06141B750A0 FOREIGN KEY (mouvement_regularisation_id) REFERENCES stk_mouvement (id)');
        $this->addSql('ALTER TABLE stk_ligne_reception_achat ADD CONSTRAINT FK_890A3DE97C14DF52 FOREIGN KEY (reception_id) REFERENCES stk_reception_achat (id)');
        $this->addSql('ALTER TABLE stk_ligne_reception_achat ADD CONSTRAINT FK_890A3DE95825957B FOREIGN KEY (article_stock_id) REFERENCES stk_article (id)');
        $this->addSql('ALTER TABLE stk_ligne_reception_achat ADD CONSTRAINT FK_890A3DE973EFD230 FOREIGN KEY (ligne_commande_achat_id) REFERENCES stk_ligne_commande_achat (id)');
        $this->addSql('ALTER TABLE stk_ligne_reception_achat ADD CONSTRAINT FK_890A3DE987280C1 FOREIGN KEY (lot_cree_id) REFERENCES stk_lot (id)');
        $this->addSql('ALTER TABLE stk_lot ADD CONSTRAINT FK_5913F3BE5825957B FOREIGN KEY (article_stock_id) REFERENCES stk_article (id)');
        $this->addSql('ALTER TABLE stk_lot ADD CONSTRAINT FK_5913F3BEFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE stk_mouvement ADD CONSTRAINT FK_A9B4C86A5825957B FOREIGN KEY (article_stock_id) REFERENCES stk_article (id)');
        $this->addSql('ALTER TABLE stk_mouvement ADD CONSTRAINT FK_A9B4C86AFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE stk_mouvement ADD CONSTRAINT FK_A9B4C86A60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE stk_parametrage ADD CONSTRAINT FK_B7BA0D53FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE stk_reception_achat ADD CONSTRAINT FK_D8B16A2D28B5C98D FOREIGN KEY (commande_achat_id) REFERENCES stk_commande_achat (id)');
        $this->addSql('ALTER TABLE stk_reception_achat ADD CONSTRAINT FK_D8B16A2DFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE stk_reception_achat ADD CONSTRAINT FK_D8B16A2D670C757F FOREIGN KEY (fournisseur_id) REFERENCES stk_fournisseur (id)');
        $this->addSql('ALTER TABLE stk_transfert ADD CONSTRAINT FK_ECAB98EFEE64CA17 FOREIGN KEY (article_stock_source_id) REFERENCES stk_article (id)');
        $this->addSql('ALTER TABLE stk_transfert ADD CONSTRAINT FK_ECAB98EF5AA54D5F FOREIGN KEY (article_stock_destination_id) REFERENCES stk_article (id)');
        $this->addSql('ALTER TABLE stk_transfert ADD CONSTRAINT FK_ECAB98EF3F48B45E FOREIGN KEY (mouvement_sortie_id) REFERENCES stk_mouvement (id)');
        $this->addSql('ALTER TABLE stk_transfert ADD CONSTRAINT FK_ECAB98EF5C41B41D FOREIGN KEY (mouvement_entree_id) REFERENCES stk_mouvement (id)');
        $this->addSql('ALTER TABLE stk_transfert ADD CONSTRAINT FK_ECAB98EF4C0C045 FOREIGN KEY (demande_par_id) REFERENCES sec_utilisateur (id)');

        // CHECK métier (plan §6, RG-STOCK-08/10) : MariaDB 11.4 applique les contraintes CHECK.
        $this->addSql('ALTER TABLE stk_lot ADD CONSTRAINT chk_lot_quantite_restante CHECK (quantite_restante <= quantite_initiale)');
        $this->addSql('ALTER TABLE stk_article ADD CONSTRAINT chk_article_seuils CHECK (seuil_min <= seuil_max)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stk_article DROP FOREIGN KEY FK_3E838FF7FF631228');
        $this->addSql('ALTER TABLE stk_article DROP FOREIGN KEY FK_3E838FF7F347EFB');
        $this->addSql('ALTER TABLE stk_catalogue_fournisseur DROP FOREIGN KEY FK_A2E5D766670C757F');
        $this->addSql('ALTER TABLE stk_catalogue_fournisseur DROP FOREIGN KEY FK_A2E5D7665825957B');
        $this->addSql('ALTER TABLE stk_commande_achat DROP FOREIGN KEY FK_24B8CC1CFF631228');
        $this->addSql('ALTER TABLE stk_commande_achat DROP FOREIGN KEY FK_24B8CC1C670C757F');
        $this->addSql('ALTER TABLE stk_fournisseur DROP FOREIGN KEY FK_C9FD5432FF631228');
        $this->addSql('ALTER TABLE stk_imputation_lot DROP FOREIGN KEY FK_1029E6458ED12402');
        $this->addSql('ALTER TABLE stk_imputation_lot DROP FOREIGN KEY FK_1029E645AF079024');
        $this->addSql('ALTER TABLE stk_inventaire DROP FOREIGN KEY FK_5F7D5039FF631228');
        $this->addSql('ALTER TABLE stk_ligne_commande_achat DROP FOREIGN KEY FK_41EF322D28B5C98D');
        $this->addSql('ALTER TABLE stk_ligne_commande_achat DROP FOREIGN KEY FK_41EF322D5825957B');
        $this->addSql('ALTER TABLE stk_ligne_inventaire DROP FOREIGN KEY FK_540FD061CE430A85');
        $this->addSql('ALTER TABLE stk_ligne_inventaire DROP FOREIGN KEY FK_540FD0615825957B');
        $this->addSql('ALTER TABLE stk_ligne_inventaire DROP FOREIGN KEY FK_540FD0619E1BD977');
        $this->addSql('ALTER TABLE stk_ligne_inventaire DROP FOREIGN KEY FK_540FD06141B750A0');
        $this->addSql('ALTER TABLE stk_ligne_reception_achat DROP FOREIGN KEY FK_890A3DE97C14DF52');
        $this->addSql('ALTER TABLE stk_ligne_reception_achat DROP FOREIGN KEY FK_890A3DE95825957B');
        $this->addSql('ALTER TABLE stk_ligne_reception_achat DROP FOREIGN KEY FK_890A3DE973EFD230');
        $this->addSql('ALTER TABLE stk_ligne_reception_achat DROP FOREIGN KEY FK_890A3DE987280C1');
        $this->addSql('ALTER TABLE stk_lot DROP FOREIGN KEY FK_5913F3BE5825957B');
        $this->addSql('ALTER TABLE stk_lot DROP FOREIGN KEY FK_5913F3BEFF631228');
        $this->addSql('ALTER TABLE stk_mouvement DROP FOREIGN KEY FK_A9B4C86A5825957B');
        $this->addSql('ALTER TABLE stk_mouvement DROP FOREIGN KEY FK_A9B4C86AFF631228');
        $this->addSql('ALTER TABLE stk_mouvement DROP FOREIGN KEY FK_A9B4C86A60BB6FE6');
        $this->addSql('ALTER TABLE stk_parametrage DROP FOREIGN KEY FK_B7BA0D53FF631228');
        $this->addSql('ALTER TABLE stk_reception_achat DROP FOREIGN KEY FK_D8B16A2D28B5C98D');
        $this->addSql('ALTER TABLE stk_reception_achat DROP FOREIGN KEY FK_D8B16A2DFF631228');
        $this->addSql('ALTER TABLE stk_reception_achat DROP FOREIGN KEY FK_D8B16A2D670C757F');
        $this->addSql('ALTER TABLE stk_transfert DROP FOREIGN KEY FK_ECAB98EFEE64CA17');
        $this->addSql('ALTER TABLE stk_transfert DROP FOREIGN KEY FK_ECAB98EF5AA54D5F');
        $this->addSql('ALTER TABLE stk_transfert DROP FOREIGN KEY FK_ECAB98EF3F48B45E');
        $this->addSql('ALTER TABLE stk_transfert DROP FOREIGN KEY FK_ECAB98EF5C41B41D');
        $this->addSql('ALTER TABLE stk_transfert DROP FOREIGN KEY FK_ECAB98EF4C0C045');
        $this->addSql('ALTER TABLE stk_lot DROP CONSTRAINT chk_lot_quantite_restante');
        $this->addSql('ALTER TABLE stk_article DROP CONSTRAINT chk_article_seuils');
        $this->addSql('DROP TABLE stk_article');
        $this->addSql('DROP TABLE stk_catalogue_fournisseur');
        $this->addSql('DROP TABLE stk_commande_achat');
        $this->addSql('DROP TABLE stk_fournisseur');
        $this->addSql('DROP TABLE stk_imputation_lot');
        $this->addSql('DROP TABLE stk_inventaire');
        $this->addSql('DROP TABLE stk_ligne_commande_achat');
        $this->addSql('DROP TABLE stk_ligne_inventaire');
        $this->addSql('DROP TABLE stk_ligne_reception_achat');
        $this->addSql('DROP TABLE stk_lot');
        $this->addSql('DROP TABLE stk_mouvement');
        $this->addSql('DROP TABLE stk_parametrage');
        $this->addSql('DROP TABLE stk_reception_achat');
        $this->addSql('DROP TABLE stk_transfert');
    }
}
