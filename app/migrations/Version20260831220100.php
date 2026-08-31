<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Import\Entity\ImportedEntityRef` (plan-import-i1.md §1/§4, D100) — nouvelle table, interne à
 * `App\Import` (pas une `#[ApiResource]`). `UNIQUE(establishment_id, type, external_ref)` est la règle
 * de rapprochement exact elle-même : jamais deux lignes pour le même triplet.
 *
 * `target_id` est volontairement sans FK (D2, polymorphe par construction — une seule table sert N
 * types de cibles dans N modules).
 */
final class Version20260831220100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Import (I1) : nouvelle table import_entity_ref.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE import_entity_ref (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                type VARCHAR(20) NOT NULL,
                external_ref VARCHAR(190) NOT NULL,
                target_id BINARY(16) NOT NULL,
                import_batch_ref BINARY(16) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_import_entity_ref (establishment_id, type, external_ref),
                INDEX idx_import_entity_ref_target (target_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE import_entity_ref ADD CONSTRAINT FK_IMPORT_ENTITY_REF_ESTABLISHMENT FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE import_entity_ref');
    }
}
