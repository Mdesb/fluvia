<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ACT-1 (D16 point 1) : quantité consommée par une réservation et par une inscription en liste
 * d'attente — une table de huit consomme huit couverts, pas un.
 *
 * ⚠ Migration écrite à la main (même précaution que `Version20260824090000.php` :
 * `doctrine:migrations:diff` propose systématiquement des suppressions d'index sur ce dépôt).
 * Relue ligne à ligne : deux `ALTER TABLE`, une colonne chacun, sur `reservation_reservation` et
 * `reservation_liste_attente` — aucun index, aucune contrainte, aucune colonne d'un autre module.
 * `uniq_liste_attente_creneau_rang` n'est pas touchée.
 *
 * `DEFAULT 1 NOT NULL` : c'est exactement ce que valait implicitement chaque ligne existante avant
 * ce lot (une réservation = une place), donc l'historique reste juste sans reprise de données, et
 * la somme de la jauge redonne l'ancien comptage.
 */
final class Version20260824182500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ACT-1 : quantity sur Reservation et ListeAttente (défaut 1, D16 point 1).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_reservation ADD quantity INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE reservation_liste_attente ADD quantity INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_liste_attente DROP quantity');
        $this->addSql('ALTER TABLE reservation_reservation DROP quantity');
    }
}
