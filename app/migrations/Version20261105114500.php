<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CODE AGENT DE CAISSE (#346) — écrite à la main, horodatée en UTC.
 *
 * - `sec_utilisateur.cash_pin_hash` / `cash_pin_failures` : le code personnel haché et le compteur
 *   d'échecs consécutifs qui le bloque ;
 * - `caisse_point_de_vente.require_agent_pin` : le guichet exige le code à l'ouverture ;
 * - `caisse_session.agent_pin_verified` : la session a été ouverte avec un code vérifié.
 *
 * Sans perte : quatre colonnes s'ajoutent, nulles ou à faux/zéro par défaut. Aucun guichet n'exige le
 * code tant que personne ne coche l'option, et aucune session passée ne se prétend vérifiée.
 */
final class Version20261105114500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse : code agent personnel (haché, compteur d\'échecs), exigence par point de vente, trace sur la session.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sec_utilisateur ADD cash_pin_hash VARCHAR(255) DEFAULT NULL, ADD cash_pin_failures INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE caisse_point_de_vente ADD require_agent_pin TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE caisse_session ADD agent_pin_verified TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE caisse_session DROP agent_pin_verified');
        $this->addSql('ALTER TABLE caisse_point_de_vente DROP require_agent_pin');
        $this->addSql('ALTER TABLE sec_utilisateur DROP cash_pin_hash, DROP cash_pin_failures');
    }
}
