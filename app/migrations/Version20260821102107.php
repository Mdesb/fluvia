<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D7-bis — table de file du transport Doctrine de messenger.
 *
 * Généré en environnement de développement : en test le transport est en mémoire, donc le diff ne voit
 * pas la table. Puis **élagué** — le diff proposait aussi 66 instructions de dérive, dont la
 * suppression de l'index FULLTEXT du module Support et 40 renommages d'index des lots Finance. Voir
 * C14 : tant que ces index ne sont pas déclarés au mapping, chaque génération les reproposera.
 */
final class Version20260821102107 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D7-bis : table de file messenger (transport Doctrine).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
    }
}
