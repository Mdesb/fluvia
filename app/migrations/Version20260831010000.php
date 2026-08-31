<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le référentiel des taux de TVA légaux : ce que la loi fixe, distinct de ce que l'exploitant saisit.
 *
 * Demandé par Maxime : « les taux de TVA sont définis par les lois, un utilisateur n'a pas besoin de
 * le créer, il faut qu'on les propose tous automatiquement, et pour tous les pays d'Europe, avec les
 * exemptions et les TVA intracommunautaires » — et, immédiatement après : « on devrait avoir un œil
 * pour masquer le taux, et ils doivent définir leur taux par défaut à la création ».
 *
 * Mesuré avant d'écrire : 31 taux en base pour un jeu de démonstration à trois établissements.
 * Chacun retape ce que la loi a déjà écrit, et ça prolifère.
 *
 * ── TROIS TABLES, ET CHACUNE RÉPOND À UNE DES TROIS PHRASES ─────────────────────────────────────
 *
 *   accounting_legal_vat_rate          ce que la loi dit — pays, catégorie, taux, dates, source
 *   accounting_hidden_legal_vat_rate   ce que CET exploitant ne veut pas voir — réversible
 *   compta_profil_exploitant       + taux_tva_par_defaut_id
 *
 * `compta_taux_tva` reçoit `origine_legale_id` : d'où vient ce taux, quand on le sait. **La valeur
 * reste sur la ligne** — le lien dit la filiation, il n'est pas la source du nombre.
 *
 * ⚠ POURQUOI CE LIEN NE DOIT JAMAIS DEVENIR LA SOURCE DU TAUX, ET C'EST MESURÉ.
 *
 * Deux des trois chaînes de scellement NF525 du produit reconstruisent leur empreinte à la
 * vérification, en relisant les entités vivantes (`ScellementFactureHandler:127`,
 * `ScellementEcritureHandler:120` — toutes deux scellent `->getTauxTva()->getTaux()`). Modifier un
 * taux existant y fait donc échouer le contrôle d'intégrité sur des documents que personne n'a
 * touchés, avec le message « la donnée a été altérée ». La troisième chaîne, celle des ventes, ne
 * dérive pas : `OperationScellee` STOCKE son payload canonique en colonne.
 *
 * Ce défaut-là est un chantier à part, tranché par Maxime et non porté ici. Ce que cette migration
 * garantit, c'est de **ne pas l'aggraver** : le référentiel est immuable, donc il n'offre aucune
 * raison de modifier un taux auquel une écriture scellée renvoie. Un décret ne modifie pas une
 * ligne — il en clôt une (`valid_until`) et en ouvre une neuve.
 *
 * ── `taux_tva_par_defaut_id` EST NULLABLE, ET C'EST DÉLIBÉRÉ ────────────────────────────────────
 *
 * Maxime le veut exigé « à la création ». Exigé par l'écran, pas par la colonne : la rendre NOT NULL
 * obligerait à remplir les profils existants, et poser 20 % pour eux serait un choix fiscal fait à
 * leur place, en silence, sur une donnée qui engage. Un profil ancien sans taux par défaut dit la
 * vérité — personne ne le lui a demandé.
 *
 * ── LE DDL EST RELEVÉ SUR LE MAPPING, PAS ÉCRIT À LA MAIN (D32) ─────────────────────────────────
 *
 * Noms d'index et de contraintes hachés compris. Une première version les nommait lisiblement ; le
 * schéma se serait mis à diverger dès le premier jour, et ces tables auraient rejoint les `RENAME
 * INDEX` que ce dépôt traîne déjà. Relevé par `SHOW CREATE TABLE` sur le schéma monté depuis le
 * mapping, avec un témoin positif pour s'assurer que la base interrogée était la bonne.
 */
final class Version20260831010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Référentiel des taux de TVA légaux (immuable et daté), masquage par exploitant, taux par défaut.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE accounting_legal_vat_rate (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                country VARCHAR(2) NOT NULL,
                category VARCHAR(20) NOT NULL,
                rate NUMERIC(5, 2) NOT NULL,
                label VARCHAR(160) NOT NULL,
                valid_from DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                valid_until DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                source VARCHAR(255) NOT NULL,
                UNIQUE INDEX uniq_legal_vat_rate (country, category, valid_from),
                INDEX idx_legal_vat_rate_country (country),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE accounting_hidden_legal_vat_rate (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                profil_exploitant_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                legal_vat_rate_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                hidden_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_hidden_legal_vat_rate (profil_exploitant_id, legal_vat_rate_id),
                INDEX IDX_D1CE946B53ABECD4 (profil_exploitant_id),
                INDEX IDX_D1CE946B564FD8A1 (legal_vat_rate_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE accounting_hidden_legal_vat_rate ADD CONSTRAINT FK_D1CE946B53ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE accounting_hidden_legal_vat_rate ADD CONSTRAINT FK_D1CE946B564FD8A1 FOREIGN KEY (legal_vat_rate_id) REFERENCES accounting_legal_vat_rate (id)');

        $this->addSql('ALTER TABLE compta_taux_tva ADD origine_legale_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE compta_taux_tva ADD CONSTRAINT FK_FAA9536FBE540FC2 FOREIGN KEY (origine_legale_id) REFERENCES accounting_legal_vat_rate (id)');
        $this->addSql('CREATE INDEX IDX_FAA9536FBE540FC2 ON compta_taux_tva (origine_legale_id)');

        $this->addSql('ALTER TABLE compta_profil_exploitant ADD taux_tva_par_defaut_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE compta_profil_exploitant ADD CONSTRAINT FK_74B2875D1C4DAAA2 FOREIGN KEY (taux_tva_par_defaut_id) REFERENCES compta_taux_tva (id)');
        $this->addSql('CREATE INDEX IDX_74B2875D1C4DAAA2 ON compta_profil_exploitant (taux_tva_par_defaut_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant DROP FOREIGN KEY FK_74B2875D1C4DAAA2');
        $this->addSql('DROP INDEX IDX_74B2875D1C4DAAA2 ON compta_profil_exploitant');
        $this->addSql('ALTER TABLE compta_profil_exploitant DROP taux_tva_par_defaut_id');

        $this->addSql('ALTER TABLE compta_taux_tva DROP FOREIGN KEY FK_FAA9536FBE540FC2');
        $this->addSql('DROP INDEX IDX_FAA9536FBE540FC2 ON compta_taux_tva');
        $this->addSql('ALTER TABLE compta_taux_tva DROP origine_legale_id');

        $this->addSql('ALTER TABLE accounting_hidden_legal_vat_rate DROP FOREIGN KEY FK_D1CE946B53ABECD4');
        $this->addSql('ALTER TABLE accounting_hidden_legal_vat_rate DROP FOREIGN KEY FK_D1CE946B564FD8A1');
        $this->addSql('DROP TABLE accounting_hidden_legal_vat_rate');
        $this->addSql('DROP TABLE accounting_legal_vat_rate');
    }
}
