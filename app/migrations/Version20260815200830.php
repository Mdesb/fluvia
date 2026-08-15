<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260815200830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profil de fonctionnalités par établissement : table fonctionnalite_etablissement (capacités activables/paramétrables par établissement).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fonctionnalite_etablissement (id BINARY(16) NOT NULL, capacite_code VARCHAR(60) NOT NULL, active TINYINT NOT NULL, parametres JSON DEFAULT NULL, modifie_le DATETIME NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_F4238793FF631228 (etablissement_id), UNIQUE INDEX uniq_fonctionnalite_etablissement_capacite (etablissement_id, capacite_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fonctionnalite_etablissement ADD CONSTRAINT FK_F4238793FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fonctionnalite_etablissement DROP FOREIGN KEY FK_F4238793FF631228');
        $this->addSql('DROP TABLE fonctionnalite_etablissement');
    }
}
