<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Colonne `plateforme` sur `sec_utilisateur` : marque un membre de l'équipe plateforme
 * (déploiement mono-propriétaire) qui voit et administre TOUS les établissements. Exception assumée
 * à RG-ED-07, lue par le seul `PlatformScope`. NOT NULL DEFAULT 0 : tous les comptes existants
 * restent cloisonnés exactement comme avant.
 */
final class Version20260923100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonne plateforme (équipe plateforme = accès à tous les sites) sur sec_utilisateur.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sec_utilisateur ADD plateforme TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sec_utilisateur DROP plateforme');
    }
}
