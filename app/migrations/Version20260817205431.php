<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Base de connaissance & Support (plan-support.md §6) : les 9 tables `App\Support`
 * (catégories, articles + versionnage + pièces jointes, journal d'import doc vivante, tickets +
 * messages + pièces jointes + liaison article, table de jointure `support_ticket_article_lie`).
 * Ajouts manuels au diff auto-généré (§1/§2/§6 du plan) :
 *  - `UNIQUE INDEX` sur `support_article_aide.cle_import` (nullable — NULL multiples autorisés par
 *    MariaDB dans un index unique, équivalent fonctionnel à l'unicité partielle demandée « si
 *    origine=import », sans clause `WHERE` non supportée par MariaDB) ;
 *  - `FULLTEXT INDEX support_ft_article_recherche` sur `recherche_texte` (SQL brut, recherche
 *    plein-texte MariaDB *natural language mode*, §2 du plan — Doctrine ORM n'a pas d'attribut natif).
 * Suppose la migration socle L0 (`org_etablissement`, `sec_utilisateur`) jouée d'abord.
 */
final class Version20260817205431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Support (plan-support.md) : 9 tables support_* + unique cle_import + FULLTEXT recherche_texte.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE support_article_aide (id BINARY(16) NOT NULL, titre VARCHAR(200) NOT NULL, slug VARCHAR(220) NOT NULL, resume VARCHAR(500) DEFAULT NULL, contenu LONGTEXT NOT NULL, mots_cles JSON DEFAULT NULL, recherche_texte LONGTEXT NOT NULL, statut VARCHAR(10) DEFAULT \'brouillon\' NOT NULL, portee VARCHAR(6) DEFAULT \'global\' NOT NULL, public_cible VARCHAR(6) NOT NULL, module_lie VARCHAR(60) DEFAULT NULL, origine VARCHAR(10) DEFAULT \'manuel\' NOT NULL, cle_import VARCHAR(255) DEFAULT NULL, hash_import_courant VARCHAR(64) DEFAULT NULL, date_creation DATETIME NOT NULL, date_derniere_modification DATETIME NOT NULL, categorie_id BINARY(16) NOT NULL, version_publiee_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) DEFAULT NULL, auteur_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_5268619D989D9B62 (slug), INDEX IDX_5268619DBCF5E72D (categorie_id), INDEX IDX_5268619D30FAACB2 (version_publiee_id), INDEX IDX_5268619DFF631228 (etablissement_id), INDEX IDX_5268619D60BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_categorie_aide (id BINARY(16) NOT NULL, nom VARCHAR(150) NOT NULL, slug VARCHAR(160) NOT NULL, ordre SMALLINT DEFAULT 0 NOT NULL, date_creation DATETIME NOT NULL, parent_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_67EC7087989D9B62 (slug), INDEX IDX_67EC7087727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_journal_import_aide (id BINARY(16) NOT NULL, chemin_fichier VARCHAR(500) NOT NULL, cle_import VARCHAR(255) NOT NULL, hash_contenu VARCHAR(64) NOT NULL, resultat VARCHAR(10) NOT NULL, message_erreur LONGTEXT DEFAULT NULL, date_import DATETIME NOT NULL, article_id BINARY(16) DEFAULT NULL, INDEX IDX_E24FF2207294869C (article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_message_ticket (id BINARY(16) NOT NULL, auteur_type VARCHAR(10) NOT NULL, contenu LONGTEXT NOT NULL, note_interne TINYINT DEFAULT 0 NOT NULL, date_creation DATETIME NOT NULL, ticket_id BINARY(16) NOT NULL, auteur_id BINARY(16) NOT NULL, INDEX IDX_69D343DD700047D2 (ticket_id), INDEX IDX_69D343DD60BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_piece_jointe_aide (id BINARY(16) NOT NULL, nom_fichier VARCHAR(255) NOT NULL, type_mime VARCHAR(100) NOT NULL, taille INT NOT NULL, url VARCHAR(500) NOT NULL, date_creation DATETIME NOT NULL, article_id BINARY(16) NOT NULL, version_id BINARY(16) DEFAULT NULL, INDEX IDX_DCE810A7294869C (article_id), INDEX IDX_DCE810A4BBC2705 (version_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_piece_jointe_ticket (id BINARY(16) NOT NULL, nom_fichier VARCHAR(255) NOT NULL, type_mime VARCHAR(100) NOT NULL, taille INT NOT NULL, url VARCHAR(500) NOT NULL, date_creation DATETIME NOT NULL, message_id BINARY(16) NOT NULL, INDEX IDX_AA39E851537A1329 (message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_ticket_support (id BINARY(16) NOT NULL, sujet VARCHAR(200) NOT NULL, description LONGTEXT NOT NULL, priorite VARCHAR(8) DEFAULT \'normale\' NOT NULL, statut VARCHAR(20) DEFAULT \'nouveau\' NOT NULL, module_concerne VARCHAR(60) DEFAULT NULL, niveau_affectation VARCHAR(2) DEFAULT NULL, date_creation DATETIME NOT NULL, date_derniere_maj DATETIME NOT NULL, date_resolution DATETIME DEFAULT NULL, date_fermeture DATETIME DEFAULT NULL, motif_fermeture VARCHAR(255) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, demandeur_id BINARY(16) NOT NULL, affecte_a_id BINARY(16) DEFAULT NULL, INDEX IDX_459CC186FF631228 (etablissement_id), INDEX IDX_459CC18695A6EE59 (demandeur_id), INDEX IDX_459CC1864ED1378 (affecte_a_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_ticket_article_lie (ticket_id BINARY(16) NOT NULL, article_id BINARY(16) NOT NULL, INDEX IDX_70BF78DE700047D2 (ticket_id), INDEX IDX_70BF78DE7294869C (article_id), PRIMARY KEY (ticket_id, article_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_version_article (id BINARY(16) NOT NULL, numero SMALLINT NOT NULL, contenu LONGTEXT NOT NULL, statut_au_moment VARCHAR(10) NOT NULL, origine VARCHAR(10) NOT NULL, date_creation DATETIME NOT NULL, article_id BINARY(16) NOT NULL, auteur_id BINARY(16) NOT NULL, INDEX IDX_D938FEEB7294869C (article_id), INDEX IDX_D938FEEB60BB6FE6 (auteur_id), UNIQUE INDEX uniq_version_article_numero (article_id, numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE support_article_aide ADD CONSTRAINT FK_5268619DBCF5E72D FOREIGN KEY (categorie_id) REFERENCES support_categorie_aide (id)');
        $this->addSql('ALTER TABLE support_article_aide ADD CONSTRAINT FK_5268619D30FAACB2 FOREIGN KEY (version_publiee_id) REFERENCES support_version_article (id)');
        $this->addSql('ALTER TABLE support_article_aide ADD CONSTRAINT FK_5268619DFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE support_article_aide ADD CONSTRAINT FK_5268619D60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE support_categorie_aide ADD CONSTRAINT FK_67EC7087727ACA70 FOREIGN KEY (parent_id) REFERENCES support_categorie_aide (id)');
        $this->addSql('ALTER TABLE support_journal_import_aide ADD CONSTRAINT FK_E24FF2207294869C FOREIGN KEY (article_id) REFERENCES support_article_aide (id)');
        $this->addSql('ALTER TABLE support_message_ticket ADD CONSTRAINT FK_69D343DD700047D2 FOREIGN KEY (ticket_id) REFERENCES support_ticket_support (id)');
        $this->addSql('ALTER TABLE support_message_ticket ADD CONSTRAINT FK_69D343DD60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE support_piece_jointe_aide ADD CONSTRAINT FK_DCE810A7294869C FOREIGN KEY (article_id) REFERENCES support_article_aide (id)');
        $this->addSql('ALTER TABLE support_piece_jointe_aide ADD CONSTRAINT FK_DCE810A4BBC2705 FOREIGN KEY (version_id) REFERENCES support_version_article (id)');
        $this->addSql('ALTER TABLE support_piece_jointe_ticket ADD CONSTRAINT FK_AA39E851537A1329 FOREIGN KEY (message_id) REFERENCES support_message_ticket (id)');
        $this->addSql('ALTER TABLE support_ticket_support ADD CONSTRAINT FK_459CC186FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE support_ticket_support ADD CONSTRAINT FK_459CC18695A6EE59 FOREIGN KEY (demandeur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE support_ticket_support ADD CONSTRAINT FK_459CC1864ED1378 FOREIGN KEY (affecte_a_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE support_ticket_article_lie ADD CONSTRAINT FK_70BF78DE700047D2 FOREIGN KEY (ticket_id) REFERENCES support_ticket_support (id)');
        $this->addSql('ALTER TABLE support_ticket_article_lie ADD CONSTRAINT FK_70BF78DE7294869C FOREIGN KEY (article_id) REFERENCES support_article_aide (id)');
        $this->addSql('ALTER TABLE support_version_article ADD CONSTRAINT FK_D938FEEB7294869C FOREIGN KEY (article_id) REFERENCES support_article_aide (id)');
        $this->addSql('ALTER TABLE support_version_article ADD CONSTRAINT FK_D938FEEB60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');

        // Unicité « partielle » cle_import (RG-SUP-08) : NULL multiples autorisés par MariaDB dans un
        // index UNIQUE — seules les lignes origine=import portent une valeur non nulle.
        $this->addSql('ALTER TABLE support_article_aide ADD CONSTRAINT uniq_article_aide_cle_import UNIQUE (cle_import)');

        // Recherche plein-texte MariaDB (§2 plan, US-SUP-01/07) : InnoDB supporte FULLTEXT nativement
        // depuis 10.0.5, aucune dépendance supplémentaire.
        $this->addSql('ALTER TABLE support_article_aide ADD FULLTEXT INDEX support_ft_article_recherche (recherche_texte)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX support_ft_article_recherche ON support_article_aide');
        $this->addSql('ALTER TABLE support_article_aide DROP CONSTRAINT uniq_article_aide_cle_import');
        $this->addSql('ALTER TABLE support_article_aide DROP FOREIGN KEY FK_5268619DBCF5E72D');
        $this->addSql('ALTER TABLE support_article_aide DROP FOREIGN KEY FK_5268619D30FAACB2');
        $this->addSql('ALTER TABLE support_article_aide DROP FOREIGN KEY FK_5268619DFF631228');
        $this->addSql('ALTER TABLE support_article_aide DROP FOREIGN KEY FK_5268619D60BB6FE6');
        $this->addSql('ALTER TABLE support_categorie_aide DROP FOREIGN KEY FK_67EC7087727ACA70');
        $this->addSql('ALTER TABLE support_journal_import_aide DROP FOREIGN KEY FK_E24FF2207294869C');
        $this->addSql('ALTER TABLE support_message_ticket DROP FOREIGN KEY FK_69D343DD700047D2');
        $this->addSql('ALTER TABLE support_message_ticket DROP FOREIGN KEY FK_69D343DD60BB6FE6');
        $this->addSql('ALTER TABLE support_piece_jointe_aide DROP FOREIGN KEY FK_DCE810A7294869C');
        $this->addSql('ALTER TABLE support_piece_jointe_aide DROP FOREIGN KEY FK_DCE810A4BBC2705');
        $this->addSql('ALTER TABLE support_piece_jointe_ticket DROP FOREIGN KEY FK_AA39E851537A1329');
        $this->addSql('ALTER TABLE support_ticket_support DROP FOREIGN KEY FK_459CC186FF631228');
        $this->addSql('ALTER TABLE support_ticket_support DROP FOREIGN KEY FK_459CC18695A6EE59');
        $this->addSql('ALTER TABLE support_ticket_support DROP FOREIGN KEY FK_459CC1864ED1378');
        $this->addSql('ALTER TABLE support_ticket_article_lie DROP FOREIGN KEY FK_70BF78DE700047D2');
        $this->addSql('ALTER TABLE support_ticket_article_lie DROP FOREIGN KEY FK_70BF78DE7294869C');
        $this->addSql('ALTER TABLE support_version_article DROP FOREIGN KEY FK_D938FEEB7294869C');
        $this->addSql('ALTER TABLE support_version_article DROP FOREIGN KEY FK_D938FEEB60BB6FE6');
        $this->addSql('DROP TABLE support_article_aide');
        $this->addSql('DROP TABLE support_categorie_aide');
        $this->addSql('DROP TABLE support_journal_import_aide');
        $this->addSql('DROP TABLE support_message_ticket');
        $this->addSql('DROP TABLE support_piece_jointe_aide');
        $this->addSql('DROP TABLE support_piece_jointe_ticket');
        $this->addSql('DROP TABLE support_ticket_support');
        $this->addSql('DROP TABLE support_ticket_article_lie');
        $this->addSql('DROP TABLE support_version_article');
    }
}
