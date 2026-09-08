<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Group — impact jauge, avec grain choisi par réservation.
 *
 * `group_booking.grain` (`per_group` / `per_person`) : à la confirmation, une réservation crée soit UNE
 * `Reservation` socle de quantité N (bloc), soit N de quantité 1 (un billet par visiteur). La jauge
 * étant la somme des `Reservation.quantity`, les deux pèsent pareil. La `group_booking_jauge_reservation`
 * porte ces réservations (1 ou N) ; elles sont libérées à l'annulation du groupe.
 *
 * SQL relevé par `doctrine:schema:update --dump-sql` sur le mapping neuf.
 */
final class Version20260908190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'group_booking.grain + group_booking_jauge_reservation : décompte jauge, grain par réservation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE group_booking ADD grain VARCHAR(12) DEFAULT 'per_group' NOT NULL");
        $this->addSql('CREATE TABLE group_booking_jauge_reservation (group_booking_id BINARY(16) NOT NULL, reservation_id BINARY(16) NOT NULL, INDEX IDX_61A2C14AD4FD7DD2 (group_booking_id), INDEX IDX_61A2C14AB83297E7 (reservation_id), PRIMARY KEY (group_booking_id, reservation_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE group_booking_jauge_reservation ADD CONSTRAINT FK_61A2C14AD4FD7DD2 FOREIGN KEY (group_booking_id) REFERENCES group_booking (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_booking_jauge_reservation ADD CONSTRAINT FK_61A2C14AB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation_reservation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE group_booking_jauge_reservation');
        $this->addSql('ALTER TABLE group_booking DROP grain');
    }
}
