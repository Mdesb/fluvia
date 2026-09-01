<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RECONCILIE `import_batch` AVEC LE MODULE RETENU.
 *
 * Deux sessions ont construit le module Import sans le savoir. Maxime a tranche le 01/09 : on garde
 * celui de `claude-E`, plus complet. La table, elle, avait deja ete creee en preproduction par la
 * migration de `claude-I`, avec un schema voisin mais different.
 *
 * ⚠ `created_rows` EST LE POINT DUR, ET IL EST SILENCIEUX A LA LECTURE. Elle est `NOT NULL` sans
 * valeur par defaut et n'existe pas dans l'entite retenue : toute insertion echouerait sur
 * « Field 'created_rows' doesn't have a default value ». Le schema et le code se contrediraient sans
 * qu'aucun test de lecture ne le montre.
 *
 * `announced_total` est nullable, donc inoffensive — mais inutilisee elle aussi. Une colonne que
 * personne n'ecrit et que personne ne lit fait chercher a quoi elle sert.
 *
 * ⚠ RIEN N'EST PERDU : la table etait vide au moment de cette migration (0 lot, 0 client repris,
 * mesure faite avant). Si elle ne l'etait pas, la reponse ne serait pas un DROP.
 */
final class Version20260901080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Reconcilie import_batch avec le module Import retenu : expected_total ajoutee, colonnes de l'autre schema retirees.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_batch ADD expected_total NUMERIC(14, 2) DEFAULT NULL');

        // ⚠ `IF EXISTS` : sur un environnement neuf, `import_batch` est creee directement au bon
        // schema par la migration du module retenu — ces deux colonnes n'y ont jamais existe. Sans
        // cette clause, la migration echouerait partout SAUF sur la preproduction.
        $this->addSql('ALTER TABLE import_batch DROP COLUMN IF EXISTS created_rows');
        $this->addSql('ALTER TABLE import_batch DROP COLUMN IF EXISTS announced_total');
    }

    public function down(Schema $schema): void
    {
        // ⚠ ON NE RESTAURE PAS `created_rows` EN `NOT NULL` SANS DEFAUT : ce serait recreer
        // exactement le blocage que cette migration leve. Si un jour on revient en arriere, la
        // colonne revient nullable et c'est a l'appelant de decider ce qu'il en fait.
        $this->addSql('ALTER TABLE import_batch ADD created_rows INT DEFAULT NULL');
        $this->addSql('ALTER TABLE import_batch ADD announced_total INT DEFAULT NULL');
        $this->addSql('ALTER TABLE import_batch DROP COLUMN expected_total');
    }
}
