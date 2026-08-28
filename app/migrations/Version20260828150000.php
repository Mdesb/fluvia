<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Zone scolaire et droit local d'Alsace-Moselle sur le réglage d'ouverture.
 *
 * Deux colonnes, deux natures. `school_zone` sert à AFFICHER les vacances en fond de calendrier :
 * elle ne ferme rien, et elle est nullable parce qu'on ne devine pas une académie. `alsace_moselle`
 * ajoute deux jours FÉRIÉS — le Vendredi saint et le 26 décembre — au titre du droit local du
 * Bas-Rhin, du Haut-Rhin et de la Moselle.
 *
 * Le drapeau est saisi et non déduit d'une adresse : l'adresse du siège n'est pas toujours celle du
 * site, et un club de Strasbourg ouvert le Vendredi saint convoque du personnel un jour chômé.
 *
 * DDL relevé sur le mapping (D32), écrit à la main, horodaté en heure locale.
 */
final class Version20260828150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réglage d’ouverture : zone scolaire et droit local d’Alsace-Moselle.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE opening_setting ADD school_zone VARCHAR(1) DEFAULT NULL, ADD alsace_moselle TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE opening_setting DROP school_zone, DROP alsace_moselle');
    }
}
