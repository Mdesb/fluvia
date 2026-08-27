<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les mentions obligatoires d'un site marchand, et la fiche qui les alimente.
 *
 * **Pourquoi deux tables et pas une.** `legal_identity` porte ce que l'exploitant saisit **une fois** —
 * raison sociale, SIRET, siège, hébergeur, médiateur — et qui apparaît dans plusieurs documents.
 * Recopié dans chaque texte, il diverge : le jour du déménagement, l'exploitant corrige les mentions
 * légales, oublie les CGV, et publie deux adresses contradictoires sur le même site.
 *
 * `legal_document` porte les textes, **versionnés**. Des CGV ne sont opposables que dans la version que
 * le client a pu lire au moment où il a payé : une modification en mars ne vaut pas pour une commande de
 * janvier, et sans conservation on n'a même pas de quoi montrer ce que disait la version de janvier.
 * D'où `status` (`draft` / `published` / `superseded`) et `version` : **rien n'écrase un texte publié**.
 *
 * **Ce que cette migration ne suffit pas à garantir.** `PanierEnLigne` horodate le consentement RGPD
 * mais n'enregistre aucune version de CGV acceptée. Le versionnement rend la preuve *possible* ; il ne
 * la constitue pas tant que le tunnel de commande n'écrit pas la version acceptée sur le panier.
 * Signalé le 27/08, non fait — et c'est la moitié manquante de la preuve.
 *
 * **`activities` décide des clauses, pas de la présentation.** Le droit de rétractation n'est pas le
 * même pour un billet daté (exception de l'art. L221-28 12°) et pour un mug de la boutique (quatorze
 * jours, formulaire type obligatoire). Un exploitant qui vend les deux doit publier les deux régimes ;
 * une colonne vide fait donc refuser la génération des CGV plutôt qu'écrire un texte au hasard.
 *
 * DDL relevé par `doctrine:schema:update --dump-sql` sur le mapping (D32) — pas par `migrations:diff`,
 * qui ratisserait la dérive et horodaterait en UTC.
 */
final class Version20260827020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mentions légales, CGV, CGU et confidentialité : fiche d’identité et documents versionnés.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE legal_identity (
                id BINARY(16) NOT NULL,
                legal_name VARCHAR(200) NOT NULL,
                legal_form VARCHAR(120) DEFAULT NULL,
                share_capital VARCHAR(60) DEFAULT NULL,
                siret VARCHAR(20) DEFAULT NULL,
                trade_register VARCHAR(120) DEFAULT NULL,
                vat_number VARCHAR(20) DEFAULT NULL,
                registered_address LONGTEXT DEFAULT NULL,
                contact_email VARCHAR(180) DEFAULT NULL,
                contact_phone VARCHAR(40) DEFAULT NULL,
                publication_director VARCHAR(150) DEFAULT NULL,
                host_name VARCHAR(200) DEFAULT NULL,
                host_address LONGTEXT DEFAULT NULL,
                host_phone VARCHAR(40) DEFAULT NULL,
                mediator_name VARCHAR(200) DEFAULT NULL,
                mediator_url VARCHAR(255) DEFAULT NULL,
                mediator_address LONGTEXT DEFAULT NULL,
                data_protection_officer VARCHAR(255) DEFAULT NULL,
                activities JSON NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_legal_identity_establishment (establishment_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4'
        );

        $this->addSql(
            'CREATE TABLE legal_document (
                id BINARY(16) NOT NULL,
                type VARCHAR(40) NOT NULL,
                title VARCHAR(200) NOT NULL,
                content LONGTEXT NOT NULL,
                status VARCHAR(20) NOT NULL,
                version INT NOT NULL,
                published_at DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL,
                missing_fields JSON NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                INDEX IDX_72A4FDB78565851 (establishment_id),
                INDEX idx_legal_document_lookup (establishment_id, type, status),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4'
        );

        $this->addSql(
            'ALTER TABLE legal_identity
             ADD CONSTRAINT FK_C0589E058565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)'
        );
        $this->addSql(
            'ALTER TABLE legal_document
             ADD CONSTRAINT FK_72A4FDB78565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)'
        );
    }

    public function down(Schema $schema): void
    {
        // Réversible sans condition : ces deux tables sont neuves et rien d'autre ne les référence.
        // La contrainte tombe avec la table, mais on la retire explicitement — un DROP qui échoue à
        // mi-parcours laisse une base dans un état que le message d'erreur n'explique pas.
        $this->addSql('ALTER TABLE legal_document DROP FOREIGN KEY FK_72A4FDB78565851');
        $this->addSql('ALTER TABLE legal_identity DROP FOREIGN KEY FK_C0589E058565851');
        $this->addSql('DROP TABLE legal_document');
        $this->addSql('DROP TABLE legal_identity');
    }
}
