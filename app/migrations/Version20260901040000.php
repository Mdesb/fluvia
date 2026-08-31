<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Import\Entity\ImportBatch` (plan-import-i1.md §1/§4) — nouvelle table. `content` mappé
 * `Types::TEXT` : la plateforme MariaDB matérialise ce type en `LONGTEXT` (jamais un `TEXT` nu limité à
 * 64 Ko), conforme au §0.1 du plan — vérifié sur le DDL ci-dessous avant commit (D32).
 *
 * `content_hash` porte un INDEX (`establishment_id`, `content_hash`) **non unique** (§0.9 du plan) : la
 * spec autorise explicitement de revalider un fichier corrigé plusieurs fois, seule l'application
 * (`POST /imports/{id}/appliquer`) refuse un doublon déjà `applied`.
 */
final class Version20260901040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Import (I1) : nouvelle table import_batch.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE import_batch (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                type VARCHAR(20) NOT NULL,
                file_name VARCHAR(255) DEFAULT NULL,
                mime_type VARCHAR(100) DEFAULT NULL,
                file_size INT DEFAULT 0 NOT NULL,
                content_hash VARCHAR(64) DEFAULT NULL,
                content LONGTEXT NOT NULL,
                expected_total NUMERIC(14, 2) DEFAULT NULL,
                status VARCHAR(10) DEFAULT 'pending' NOT NULL,
                row_count INT DEFAULT 0 NOT NULL,
                errors JSON DEFAULT NULL,
                created_at DATETIME NOT NULL,
                created_by_id BINARY(16) DEFAULT NULL,
                applied_at DATETIME DEFAULT NULL,
                INDEX idx_import_batch_establishment (establishment_id),
                INDEX idx_import_batch_hash (establishment_id, content_hash),
                INDEX idx_import_batch_status (status),
                INDEX idx_import_batch_created_by (created_by_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE import_batch ADD CONSTRAINT FK_IMPORT_BATCH_ESTABLISHMENT FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE import_batch ADD CONSTRAINT FK_IMPORT_BATCH_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES sec_utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE import_batch');
    }
}
