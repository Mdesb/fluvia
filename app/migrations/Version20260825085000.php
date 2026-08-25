<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La trace d'exécution des tâches périodiques (D36).
 *
 * Sans cette table, un ordonnanceur silencieux est indiscernable d'un ordonnanceur absent — et c'est
 * précisément le défaut qu'on répare : vingt-deux commandes de domaine attendaient depuis des semaines
 * un cron que personne n'avait posé, et rien ne le signalait. La règle tirée du 24/08 est qu'un
 * mécanisme doit pouvoir **prouver** qu'il s'est exécuté.
 *
 * Écrite à la main. Le brouillon de `migrations:diff` proposait, comme toujours sur ce dépôt, la
 * suppression de la file de messages et d'un index de recherche (D32). Horodatée en heure **locale** :
 * le conteneur tourne en UTC, deux heures derrière, et une migration née en UTC se classerait avant
 * celles déjà appliquées.
 */
final class Version20260825085000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Trace d'exécution des tâches périodiques : ce qui a tourné, quand, et avec quel résultat.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_scheduled_task_run (
                id BINARY(16) NOT NULL,
                command VARCHAR(120) NOT NULL,
                last_started_at DATETIME DEFAULT NULL,
                last_finished_at DATETIME DEFAULT NULL,
                last_exit_code INT DEFAULT NULL,
                last_error LONGTEXT DEFAULT NULL,
                run_count INT DEFAULT 0 NOT NULL,
                failure_count INT DEFAULT 0 NOT NULL,
                UNIQUE INDEX uniq_scheduled_task_run_command (command),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_scheduled_task_run');
    }
}
