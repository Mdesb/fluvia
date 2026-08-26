<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D51 : `off_saison` et `off_tranche_qf` deviennent **entierement cloisonnees**.
 *
 * ⚠ Migration ecrite a la main (D32), horodatee en **heure locale** (20:29). DDL releve par `SHOW CREATE TABLE` sur les
 * tables que Doctrine cree depuis le mapping.
 *
 * **Pas de socle ici, contrairement aux types de tarif et aux categories.** Une saison est decidee par
 * l exploitant. Une tranche de quotient familial est fixee par la commune ou la CAF : deux
 * etablissements de deux communes ont des grilles differentes, et **un tarif calcule sur la mauvaise
 * grille est une erreur de facturation opposable**. D51 : *il n y a pas d arbitrage a rendre.*
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * **CETTE MIGRATION LAISSE LES LIGNES EXISTANTES ORPHELINES, ET C EST LA DECISION.**
 *
 * `etablissement_id` reste nullable et n est **pas** repris. Les saisons et les tranches creees quand
 * ces tables etaient globales n appartiennent donc a personne — et une ligne sans etablissement n est
 * visible de personne.
 *
 * L autre option etait de tout rattacher a un etablissement choisi. Elle aurait produit des tarifs
 * calcules sur la saison d un autre, et des grilles de quotient familial attribuees a une commune qui
 * ne les a pas votees. **Une migration ne fabrique jamais de donnee metier** : ce qui manque doit
 * rester visiblement manquant. Une donnee absente se remarque et se corrige ; une donnee fausse ne se
 * voit pas.
 *
 * Consequence assumee : sur un environnement existant, l exploitant recree ses saisons et ses
 * tranches. C est un travail d exploitation, pas un `up()` — et le confondre reviendrait a inscrire
 * dans le code de production une reparation qui n a de sens que sur un jeu d essai.
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * La colonne reste nullable **pour cette raison seule**. Elle n exprime pas que le rattachement soit
 * facultatif : `TenantReferenceProcessor` refuse toute creation sans etablissement actif, plutot que
 * de produire une ligne orpheline que son auteur croirait enregistree.
 */
final class Version20260826202900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D51 : cloisonnement de off_saison et off_tranche_qf (lignes existantes laissees orphelines).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE off_saison ADD etablissement_id BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_5E148BE2FF631228 ON off_saison (etablissement_id)');
        $this->addSql('ALTER TABLE off_saison ADD CONSTRAINT FK_5E148BE2FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');

        $this->addSql('ALTER TABLE off_tranche_qf ADD etablissement_id BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_B13D5DA3FF631228 ON off_tranche_qf (etablissement_id)');
        $this->addSql('ALTER TABLE off_tranche_qf ADD CONSTRAINT FK_B13D5DA3FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE off_saison DROP FOREIGN KEY FK_5E148BE2FF631228');
        $this->addSql('DROP INDEX IDX_5E148BE2FF631228 ON off_saison');
        $this->addSql('ALTER TABLE off_saison DROP etablissement_id');

        $this->addSql('ALTER TABLE off_tranche_qf DROP FOREIGN KEY FK_B13D5DA3FF631228');
        $this->addSql('DROP INDEX IDX_B13D5DA3FF631228 ON off_tranche_qf');
        $this->addSql('ALTER TABLE off_tranche_qf DROP etablissement_id');
    }
}
