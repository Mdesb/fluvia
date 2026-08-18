<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * App\OptionProduit (options sur les produits) : GroupeOption (opt_groupe), ValeurOption
 * (opt_valeur, impact tarifaire montant/pourcentage, lien informationnel vers stk_article),
 * OptionProduit (opt_option_produit, pivot Produit×GroupeOption, obligatoire/ordreAffichage propres
 * au produit, contrainte unique produit+groupe) + restriction d'établissement
 * (opt_option_produit_etablissement). Additif pur sur vente_ligne : optionsSelectionnees (JSON,
 * snapshot figé RG-OPT-09) et impactOptionsUnitaire (decimal, RG-OPT-04) — aucune colonne existante
 * modifiée/supprimée.
 */
final class Version20260818113109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\OptionProduit : GroupeOption/ValeurOption/OptionProduit (+ restriction établissement) et additif vente_ligne (optionsSelectionnees, impactOptionsUnitaire).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE opt_groupe (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, mode_selection VARCHAR(8) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE opt_valeur (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, impact_type VARCHAR(11) NOT NULL, impact_valeur NUMERIC(10, 2) NOT NULL, ordre_affichage SMALLINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, groupe_option_id BINARY(16) NOT NULL, article_stock_id BINARY(16) DEFAULT NULL, INDEX IDX_64C49E98F750CE58 (groupe_option_id), INDEX IDX_64C49E985825957B (article_stock_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE opt_option_produit (id BINARY(16) NOT NULL, obligatoire TINYINT DEFAULT 0 NOT NULL, ordre_affichage SMALLINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, produit_id BINARY(16) NOT NULL, groupe_option_id BINARY(16) NOT NULL, INDEX IDX_23B737A2F347EFB (produit_id), INDEX IDX_23B737A2F750CE58 (groupe_option_id), UNIQUE INDEX uniq_option_produit_groupe (produit_id, groupe_option_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE opt_option_produit_etablissement (option_produit_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_45D6F85FE25D66CB (option_produit_id), INDEX IDX_45D6F85FFF631228 (etablissement_id), PRIMARY KEY (option_produit_id, etablissement_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE opt_valeur ADD CONSTRAINT FK_64C49E98F750CE58 FOREIGN KEY (groupe_option_id) REFERENCES opt_groupe (id)');
        $this->addSql('ALTER TABLE opt_valeur ADD CONSTRAINT FK_64C49E985825957B FOREIGN KEY (article_stock_id) REFERENCES stk_article (id)');
        $this->addSql('ALTER TABLE opt_option_produit ADD CONSTRAINT FK_23B737A2F347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id)');
        $this->addSql('ALTER TABLE opt_option_produit ADD CONSTRAINT FK_23B737A2F750CE58 FOREIGN KEY (groupe_option_id) REFERENCES opt_groupe (id)');
        $this->addSql('ALTER TABLE opt_option_produit_etablissement ADD CONSTRAINT FK_45D6F85FE25D66CB FOREIGN KEY (option_produit_id) REFERENCES opt_option_produit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE opt_option_produit_etablissement ADD CONSTRAINT FK_45D6F85FFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vente_ligne ADD options_selectionnees JSON DEFAULT NULL, ADD impact_options_unitaire NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_ligne DROP options_selectionnees, DROP impact_options_unitaire');
        $this->addSql('ALTER TABLE opt_option_produit_etablissement DROP FOREIGN KEY FK_45D6F85FE25D66CB');
        $this->addSql('ALTER TABLE opt_option_produit_etablissement DROP FOREIGN KEY FK_45D6F85FFF631228');
        $this->addSql('ALTER TABLE opt_option_produit DROP FOREIGN KEY FK_23B737A2F347EFB');
        $this->addSql('ALTER TABLE opt_option_produit DROP FOREIGN KEY FK_23B737A2F750CE58');
        $this->addSql('ALTER TABLE opt_valeur DROP FOREIGN KEY FK_64C49E98F750CE58');
        $this->addSql('ALTER TABLE opt_valeur DROP FOREIGN KEY FK_64C49E985825957B');
        $this->addSql('DROP TABLE opt_option_produit_etablissement');
        $this->addSql('DROP TABLE opt_valeur');
        $this->addSql('DROP TABLE opt_option_produit');
        $this->addSql('DROP TABLE opt_groupe');
    }
}
