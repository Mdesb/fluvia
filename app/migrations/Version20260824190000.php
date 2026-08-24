<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SOC-1 — coffre à jetons du module de publication sociale (D14) : table `social_account`.
 *
 * Écrite à la main, et c'est délibéré. `doctrine:migrations:diff` compare les métadonnées à la base
 * et ramasse donc, en plus de la table voulue, **toute la dérive du dépôt au moment où on le lance** :
 * la version générée le 24/08 à 16:44 UTC contenait la table d'une autre session, une quinzaine de
 * renommages d'index Finance/DMS, la suppression de `messenger_messages` et celle d'un index FULLTEXT
 * du module support. Fusionner cela aurait détruit la file asynchrone et un index de recherche, pour
 * un lot qui ne prétend créer qu'une table.
 *
 * Horodatage en heure locale (19:00 CEST) et non en UTC : le conteneur PHP tourne en UTC, deux heures
 * derrière, et une migration nommée 16:44 se serait classée **avant** la 20260824181500 déjà
 * appliquée — donc exécutée hors séquence sur les bases existantes.
 */
final class Version20260824190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SOC-1 : comptes sociaux connectes (coffre a jetons chiffres, cloisonne par etablissement).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE social_account (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                network VARCHAR(32) NOT NULL,
                host VARCHAR(255) DEFAULT NULL,
                remote_account_id VARCHAR(191) NOT NULL,
                handle VARCHAR(191) NOT NULL,
                access_token_encrypted LONGTEXT DEFAULT NULL,
                refresh_token_encrypted LONGTEXT DEFAULT NULL,
                token_expires_at DATETIME DEFAULT NULL,
                status VARCHAR(32) DEFAULT 'connected' NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_F24D83398565851 (establishment_id),
                UNIQUE INDEX uniq_social_account_establishment_network_remote (establishment_id, network, remote_account_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql('ALTER TABLE social_account ADD CONSTRAINT FK_F24D83398565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_account DROP FOREIGN KEY FK_F24D83398565851');
        $this->addSql('DROP TABLE social_account');
    }
}
