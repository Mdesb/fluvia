<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Group, Lot 2 (facturation) : une réservation de groupe peut porter le **devis** généré pour
 * son payeur — début de la chaîne devis → bon de commande → facture NF525. FK vers `billing_document`
 * (`App\Facturation\Entity\CommercialDocument`).
 *
 * SQL relevé par `doctrine:schema:update --dump-sql` sur le mapping neuf.
 */
final class Version20260908150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'group_booking.commercial_document_id : devis rattaché à une réservation de groupe.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_booking ADD commercial_document_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE group_booking ADD CONSTRAINT FK_660CB9D2CDFE2BB6 FOREIGN KEY (commercial_document_id) REFERENCES billing_document (id)');
        $this->addSql('CREATE INDEX IDX_660CB9D2CDFE2BB6 ON group_booking (commercial_document_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_booking DROP FOREIGN KEY FK_660CB9D2CDFE2BB6');
        $this->addSql('DROP INDEX IDX_660CB9D2CDFE2BB6 ON group_booking');
        $this->addSql('ALTER TABLE group_booking DROP commercial_document_id');
    }
}
