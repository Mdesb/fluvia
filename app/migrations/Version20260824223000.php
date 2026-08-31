<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SOC-3 — instantanés de statistiques (D14 contrainte 2) et bornes de la collecte.
 *
 * `social_metric_snapshot` : un relevé daté par passage, jamais un compteur écrasé. Les plateformes ne
 * rendent leurs statistiques que sur une fenêtre limitée — sans instantanés pris dès maintenant,
 * l'historique n'existera pas et sera irrattrapable. `raw_payload` conserve ce qu'on a reçu **en plus**
 * de la vue normalisée : le jour où « portée » ne veut plus dire la même chose, c'est la seule façon de
 * s'en apercevoir et de recalculer le passé.
 *
 * Trois colonnes ajoutées à `social_publication` :
 * - `queued_at` — garde contre la double mise en file. Deux passages rapprochés de l'ordonnanceur
 *   publieraient sinon deux fois le même message sur le fil public d'un client, et un doublon paru ne
 *   se rattrape pas.
 * - `metrics_stopped_at` / `metrics_stopped_reason` — borne de la collecte. Un statut supprimé chez le
 *   réseau ne réapparaîtra pas ; sans cette borne on le redemanderait à chaque passage, pour toujours,
 *   en consommant le quota de l'établissement.
 *
 * Conforme à D32 : brouillon `doctrine:migrations:diff` relu ligne à ligne, seules les instructions
 * provoquées par ce lot conservées. Horodatage en heure locale.
 */
final class Version20260824223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SOC-3 : instantanes de statistiques sociales, garde de mise en file et borne de collecte.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE social_metric_snapshot (
                id BINARY(16) NOT NULL,
                publication_id BINARY(16) NOT NULL,
                collected_at DATETIME NOT NULL,
                like_count INT DEFAULT NULL,
                share_count INT DEFAULT NULL,
                reply_count INT DEFAULT NULL,
                impression_count INT DEFAULT NULL,
                raw_payload JSON NOT NULL,
                INDEX IDX_3941D88338B217A7 (publication_id),
                INDEX idx_social_snapshot_publication_collected (publication_id, collected_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql('ALTER TABLE social_metric_snapshot ADD CONSTRAINT FK_3941D88338B217A7 FOREIGN KEY (publication_id) REFERENCES social_publication (id)');

        $this->addSql('ALTER TABLE social_publication ADD queued_at DATETIME DEFAULT NULL, ADD metrics_stopped_at DATETIME DEFAULT NULL, ADD metrics_stopped_reason VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_publication DROP queued_at, DROP metrics_stopped_at, DROP metrics_stopped_reason');
        $this->addSql('ALTER TABLE social_metric_snapshot DROP FOREIGN KEY FK_3941D88338B217A7');
        $this->addSql('DROP TABLE social_metric_snapshot');
    }
}
