<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BT-5 (la devise) ET BT-130 (l'unité de mesure) — les deux seuls termes d'EN 16931 sans champ.
 *
 * Mesure du 02/09 par `facturation:einvoicing:etat`, sur les 28 termes obligatoires : tout le reste
 * existe dans le modèle et relève d'une saisie. Ces deux-là n'avaient **aucune colonne**.
 *
 * ── LES DEUX DÉFAUTS EXPLICITENT CE QUI ÉTAIT DÉJÀ VRAI (D66-ter) ───────────────────────────────
 *
 * Une migration ne fabrique pas de donnée métier. Ces deux valeurs par défaut ne fabriquent rien :
 *
 *   `EUR`   était implicite dans tout le dépôt. Aucun autre code de devise n'y apparaît, aucun champ
 *           ne le disait, et tous les montants existants sont en euros. La colonne le nomme.
 *   `C62`   est « one » dans la recommandation 20 de l'UN/ECE — l'unité sans dimension. Une ligne
 *           dont la quantité valait « 3 » comptait déjà trois *choses* : le code écrit ce compte.
 *
 * Le jour où une facture sera libellée en autre chose, ce sera parce que quelqu'un l'aura choisi.
 *
 * ── ⚠ NOT NULL AVEC DÉFAUT, JAMAIS SANS ────────────────────────────────────────────────────────
 *
 * Une colonne `NOT NULL` sans valeur par défaut fait échouer toute insertion par du code qui ne la
 * connaît pas encore — « Field 'x' doesn't have a default value ». C'est exactement le défaut qui a
 * demandé une migration de réconciliation la veille, sur `import_batch.created_rows`. Les deux
 * colonnes ci-dessous portent donc leur défaut dans la même instruction que leur contrainte.
 */
final class Version20260902010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'EN 16931 : ajoute la devise de la facture (BT-5) et l unite de mesure des lignes (BT-130).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE facturation_facture ADD currency VARCHAR(3) DEFAULT 'EUR' NOT NULL");
        $this->addSql("ALTER TABLE facturation_ligne ADD unit_code VARCHAR(3) DEFAULT 'C62' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // Le retour en arrière retire deux colonnes que rien d'autre ne référence : aucune donnée
        // métier n'est perdue, seulement l'explicitation de ce qui redeviendrait implicite.
        $this->addSql('ALTER TABLE facturation_facture DROP currency');
        $this->addSql('ALTER TABLE facturation_ligne DROP unit_code');
    }
}
