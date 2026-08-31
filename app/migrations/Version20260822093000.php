<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ACC-3 (`plan-acc3.md` §4) : ajoute `DroitAcces.reservationRef` (miroir de `billetSupportRef`/
 * `produitRef`, pas de FK Doctrine — cloisonnement inter-module, référence logique `Reservation.id`),
 * consommé par `App\Reservation\Service\ProjectionAccesReservationHandler` pour tracer quelle
 * réservation a produit un `DroitAcces` de type `Booking`.
 *
 * ⚠ Migration écrite à la main (DDL, cf. plan §4) — `doctrine:migrations:diff` reproposant
 * systématiquement la suppression de l'index FULLTEXT `App\Support` et des renommages d'index Finance
 * sur ce dépôt (piège C14, cf. `Version20260821102107.php`/`Version20260822090000.php`), elle n'a
 * **pas** été utilisée pour générer ce fichier ; seule l'instruction `ALTER TABLE acces_droit_acces ADD
 * reservation_ref` figure ici.
 */
final class Version20260822093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ACC-3 : acces_droit_acces.reservation_ref (nullable, miroir billet_support_ref/produit_ref).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE acces_droit_acces ADD reservation_ref BINARY(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE acces_droit_acces DROP reservation_ref');
    }
}
