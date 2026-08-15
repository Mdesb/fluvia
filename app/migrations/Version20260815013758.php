<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L4 (Comptabilité & Régie) : élargit `compta_etalement_pca.nature` à VARCHAR(24) — la valeur enum
 * `NaturePca::ALaConsommation` ("a_la_consommation", 18 caractères) dépassait la longueur initiale
 * (16), provoquant une troncature bloquante (SQLSTATE 22001) à l'insertion.
 */
final class Version20260815013758 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'L4 (Comptabilité & Régie) : élargit compta_etalement_pca.nature à VARCHAR(24).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compta_etalement_pca CHANGE nature nature VARCHAR(24) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compta_etalement_pca CHANGE nature nature VARCHAR(16) NOT NULL');
    }
}
