<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ACT-1 point 2 (D16) : l'**instance** affectée à une réservation faite sur un **type**
 * (`ressource_affectee_id`). « Personne ne réserve la chambre 214 : on réserve une chambre double. »
 *
 * ⚠ Migration écrite à la main (D32), horodatée en **heure locale** (23:43) et non en UTC. Le DDL
 * n'est pas deviné : relevé par `SHOW CREATE TABLE` sur la table que Doctrine crée réellement depuis
 * le mapping, nom d'index et de contrainte compris, pour qu'un futur `migrations:diff` ne propose pas
 * de les renommer.
 *
 * Une colonne ajoutée, nullable, avec son index et sa clé étrangère — aucune reprise de données :
 * `null` est la valeur juste pour toute réservation antérieure, aucune n'ayant été faite sur un type.
 * Clé étrangère réelle ici (et non référence libre comme `credit_droit_ref`) : les deux tables
 * appartiennent au **même** module, donc la contrainte ne fige l'ordre de suppression d'aucun autre
 * domaine.
 */
final class Version20260824234300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ACT-1 point 2 : ressource_affectee_id sur Reservation (instance affectée à un type).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_reservation ADD ressource_affectee_id BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_8EA4940976EE0610 ON reservation_reservation (ressource_affectee_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE reservation_reservation
                ADD CONSTRAINT FK_8EA4940976EE0610 FOREIGN KEY (ressource_affectee_id)
                    REFERENCES reservation_ressource (id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_reservation DROP FOREIGN KEY FK_8EA4940976EE0610');
        $this->addSql('DROP INDEX IDX_8EA4940976EE0610 ON reservation_reservation');
        $this->addSql('ALTER TABLE reservation_reservation DROP ressource_affectee_id');
    }
}
