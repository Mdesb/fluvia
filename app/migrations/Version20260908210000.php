<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Group — gratuités transverses : un `group_gratuite_contingent` (enveloppe de gratuités par
 * établissement) et les `group_gratuite` accordées à une réservation depuis un contingent. Les
 * entrées gratuites sortent du décompte payant du devis. Repris du musée, rendu transverse.
 *
 * SQL relevé par `doctrine:schema:update --dump-sql` sur le mapping neuf.
 */
final class Version20260908210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Group : contingents de gratuité (group_gratuite_contingent) et gratuités accordées (group_gratuite).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE group_gratuite_contingent (id BINARY(16) NOT NULL, label VARCHAR(160) NOT NULL, motif VARCHAR(120) DEFAULT NULL, quota INT DEFAULT 0 NOT NULL, consomme INT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_FD4FB30FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE group_gratuite (id BINARY(16) NOT NULL, quantite INT DEFAULT 1 NOT NULL, motif VARCHAR(120) DEFAULT NULL, booking_id BINARY(16) NOT NULL, contingent_id BINARY(16) NOT NULL, INDEX IDX_6F5DE8623301C60 (booking_id), INDEX IDX_6F5DE862635D1086 (contingent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE group_gratuite_contingent ADD CONSTRAINT FK_FD4FB30FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE group_gratuite ADD CONSTRAINT FK_6F5DE8623301C60 FOREIGN KEY (booking_id) REFERENCES group_booking (id)');
        $this->addSql('ALTER TABLE group_gratuite ADD CONSTRAINT FK_6F5DE862635D1086 FOREIGN KEY (contingent_id) REFERENCES group_gratuite_contingent (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_gratuite DROP FOREIGN KEY FK_6F5DE862635D1086');
        $this->addSql('DROP TABLE group_gratuite');
        $this->addSql('DROP TABLE group_gratuite_contingent');
    }
}
