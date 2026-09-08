<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Group — impact jauge : une réservation de groupe confirmée porte la `Reservation` socle
 * créée pour décompter la jauge de son créneau (annulée quand le groupe l'est, ce qui libère les
 * places). FK vers `reservation_reservation`.
 *
 * SQL relevé par `doctrine:schema:update --dump-sql` sur le mapping neuf.
 */
final class Version20260908190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'group_booking.jauge_reservation_id : réservation socle décomptant la jauge du créneau.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_booking ADD jauge_reservation_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE group_booking ADD CONSTRAINT FK_660CB9D245CF963E FOREIGN KEY (jauge_reservation_id) REFERENCES reservation_reservation (id)');
        $this->addSql('CREATE INDEX IDX_660CB9D245CF963E ON group_booking (jauge_reservation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_booking DROP FOREIGN KEY FK_660CB9D245CF963E');
        $this->addSql('DROP INDEX IDX_660CB9D245CF963E ON group_booking');
        $this->addSql('ALTER TABLE group_booking DROP jauge_reservation_id');
    }
}
