<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260817134502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CA-12 — unicité du code de support (identifiant_support) émis par App\Vente\Service\GenerateurCodeSupport.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_billet_support_identifiant ON vente_billet_support (identifiant_support)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_billet_support_identifiant ON vente_billet_support');
    }
}
