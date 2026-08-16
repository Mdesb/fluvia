<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260816100506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coffre IBAN réversible (App\\Sepa) : ajout iban_chiffre (sepa_mandat) et creancier_iban_chiffre (sepa_config_creancier).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sepa_config_creancier ADD creancier_iban_chiffre LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE sepa_mandat ADD iban_chiffre LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sepa_config_creancier DROP creancier_iban_chiffre');
        $this->addSql('ALTER TABLE sepa_mandat DROP iban_chiffre');
    }
}
