<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module GED (`App\Dms`, lot DMS-1, plan-dms.md §4/§1) : tables `dms_document`, `dms_document_version`,
 * `dms_document_public_link`, `dms_retention_policy` + seed du catalogue `RetentionPolicy` v1.
 *
 * ⚠ Migration écrite à la main (DDL, patron `Version20260818090400.php`) — `doctrine:migrations:diff`
 * reproposant systématiquement la suppression d'index d'autres modules (notamment le FULLTEXT
 * `App\Support`) sur ce dépôt, elle n'a **pas** été utilisée pour générer ce fichier ; seules les
 * instructions `CREATE TABLE dms_*`/`ALTER TABLE dms_* ADD CONSTRAINT FK_DMS_*` figurent ici.
 *
 * FK circulaire `dms_document.current_version_id` ⇄ `dms_document_version.document_id` (plan §0.1) :
 * `current_version_id` est **NULLABLE** au schéma (contrainte technique MariaDB, pas de FK différée) —
 * l'invariant métier « toujours renseigné » est garanti par `UploadDocumentHandler` (transaction unique,
 * jamais observable null en sortie API). Les deux tables sont créées avant l'ajout des `FOREIGN KEY`
 * qui les lient, dans les deux sens.
 *
 * `purged_at` sur `dms_document_version` (ajout au-delà du littéral de la spec, plan §14 pt.10) :
 * distingue une version historique intacte d'une version dont le contenu physique a été retiré par
 * `dms:purger-documents-expires` (RG-DMS-15) — les métadonnées restent pour l'audit (RG-DMS-14).
 *
 * ⚠ Valeurs légales exactes du catalogue `RetentionPolicy` v1 (durées, bases légales) proposées par
 * analogie (10 ans pièces comptables France, 5 ans RH) — **à faire valider par un expert compta/RH
 * avant mise en production** (même réserve que les autres modules Finance/Compta de ce dépôt, plan §14
 * pt.6).
 */
final class Version20260822090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module GED (App\\Dms, DMS-1) : dms_document, dms_document_version, dms_document_public_link, dms_retention_policy + seed catalogue rétention v1.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dms_document ('
            . 'id BINARY(16) NOT NULL, '
            . 'establishment_id BINARY(16) NOT NULL, '
            . 'category VARCHAR(30) NOT NULL, '
            . 'title VARCHAR(255) NOT NULL, '
            . 'tags JSON DEFAULT NULL, '
            . 'source_module VARCHAR(60) DEFAULT NULL, '
            . 'current_version_id BINARY(16) DEFAULT NULL, '
            . "status VARCHAR(10) DEFAULT 'active' NOT NULL, "
            . 'retention_policy_id BINARY(16) DEFAULT NULL, '
            . 'retain_until DATE DEFAULT NULL, '
            . 'created_by_id BINARY(16) DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'updated_at DATETIME NOT NULL, '
            . 'deleted_at DATETIME DEFAULT NULL, '
            . 'INDEX IDX_DMS_DOCUMENT_ETAB_CATEGORY (establishment_id, category), '
            . 'INDEX IDX_DMS_DOCUMENT_ETAB_STATUS (establishment_id, status), '
            . 'INDEX IDX_DMS_DOCUMENT_CURRENT_VERSION (current_version_id), '
            . 'INDEX IDX_DMS_DOCUMENT_RETENTION_POLICY (retention_policy_id), '
            . 'INDEX IDX_DMS_DOCUMENT_CREATED_BY (created_by_id), '
            . 'INDEX idx_dms_document_retain_until (retain_until), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE dms_document_version ('
            . 'id BINARY(16) NOT NULL, '
            . 'document_id BINARY(16) NOT NULL, '
            . 'version_number INT NOT NULL, '
            . 'previous_version_id BINARY(16) DEFAULT NULL, '
            . 'storage_key VARCHAR(190) NOT NULL, '
            . 'file_hash CHAR(64) NOT NULL, '
            . 'size_bytes INT NOT NULL, '
            . 'mime_type VARCHAR(127) NOT NULL, '
            . 'original_filename VARCHAR(255) NOT NULL, '
            . 'uploaded_by_id BINARY(16) DEFAULT NULL, '
            . 'uploaded_at DATETIME NOT NULL, '
            . 'purged_at DATETIME DEFAULT NULL, '
            . 'UNIQUE INDEX uniq_dms_version_document_number (document_id, version_number), '
            . 'UNIQUE INDEX uniq_dms_version_storage_key (storage_key), '
            . 'INDEX IDX_DMS_VERSION_PREVIOUS (previous_version_id), '
            . 'INDEX IDX_DMS_VERSION_UPLOADED_BY (uploaded_by_id), '
            . 'INDEX idx_dms_version_uploaded_at (uploaded_at), '
            . 'INDEX idx_dms_version_file_hash (file_hash), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE dms_document_public_link ('
            . 'id BINARY(16) NOT NULL, '
            . 'document_id BINARY(16) NOT NULL, '
            . 'version_id BINARY(16) NOT NULL, '
            . 'token_hash CHAR(64) NOT NULL, '
            . 'expires_at DATETIME NOT NULL, '
            . 'revoked_at DATETIME DEFAULT NULL, '
            . 'created_by_id BINARY(16) NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'access_count INT DEFAULT 0 NOT NULL, '
            . 'last_accessed_at DATETIME DEFAULT NULL, '
            . 'UNIQUE INDEX uniq_dms_public_link_token_hash (token_hash), '
            . 'INDEX IDX_DMS_PUBLIC_LINK_DOCUMENT (document_id), '
            . 'INDEX IDX_DMS_PUBLIC_LINK_VERSION (version_id), '
            . 'INDEX IDX_DMS_PUBLIC_LINK_CREATED_BY (created_by_id), '
            . 'INDEX idx_dms_public_link_expires_at (expires_at), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE dms_retention_policy ('
            . 'id BINARY(16) NOT NULL, '
            . 'code VARCHAR(60) NOT NULL, '
            . 'duration_months INT NOT NULL, '
            . 'legal_basis_key VARCHAR(120) NOT NULL, '
            . 'default_for_category VARCHAR(30) DEFAULT NULL, '
            . 'UNIQUE INDEX uniq_dms_retention_policy_code (code), '
            . 'UNIQUE INDEX uniq_dms_retention_policy_default_category (default_for_category), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        // FK vers les tables socle.
        $this->addSql('ALTER TABLE dms_document ADD CONSTRAINT FK_DMS_DOCUMENT_ETAB FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE dms_document ADD CONSTRAINT FK_DMS_DOCUMENT_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE dms_document_version ADD CONSTRAINT FK_DMS_VERSION_UPLOADED_BY FOREIGN KEY (uploaded_by_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE dms_document_public_link ADD CONSTRAINT FK_DMS_PUBLIC_LINK_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES sec_utilisateur (id)');

        // FK internes — circulaire Document <-> DocumentVersion (plan §0.1), ajoutée après création
        // des deux tables.
        $this->addSql('ALTER TABLE dms_document ADD CONSTRAINT FK_DMS_DOCUMENT_CURRENT_VERSION FOREIGN KEY (current_version_id) REFERENCES dms_document_version (id)');
        $this->addSql('ALTER TABLE dms_document ADD CONSTRAINT FK_DMS_DOCUMENT_RETENTION_POLICY FOREIGN KEY (retention_policy_id) REFERENCES dms_retention_policy (id)');
        $this->addSql('ALTER TABLE dms_document_version ADD CONSTRAINT FK_DMS_VERSION_DOCUMENT FOREIGN KEY (document_id) REFERENCES dms_document (id)');
        $this->addSql('ALTER TABLE dms_document_version ADD CONSTRAINT FK_DMS_VERSION_PREVIOUS FOREIGN KEY (previous_version_id) REFERENCES dms_document_version (id)');
        $this->addSql('ALTER TABLE dms_document_public_link ADD CONSTRAINT FK_DMS_PUBLIC_LINK_DOCUMENT FOREIGN KEY (document_id) REFERENCES dms_document (id)');
        $this->addSql('ALTER TABLE dms_document_public_link ADD CONSTRAINT FK_DMS_PUBLIC_LINK_VERSION FOREIGN KEY (version_id) REFERENCES dms_document_version (id)');

        // Seed du catalogue RetentionPolicy v1 (RG-DMS-11) — ⚠ valeurs à confirmer, cf. docblock.
        $this->addSql(
            'INSERT INTO dms_retention_policy (id, code, duration_months, legal_basis_key, default_for_category) VALUES (?, ?, ?, ?, ?)',
            [Uuid::v4()->toBinary(), 'fr_accounting_10y', 120, 'dms.retention.fr_accounting_10y', 'accounting_piece'],
        );
        $this->addSql(
            'INSERT INTO dms_retention_policy (id, code, duration_months, legal_basis_key, default_for_category) VALUES (?, ?, ?, ?, ?)',
            [Uuid::v4()->toBinary(), 'fr_hr_5y', 60, 'dms.retention.fr_hr_5y', 'hr_document'],
        );

        // Permissions dms.* (RG-SOCLE-02) — idempotent, l'affectation aux rôles relève des fixtures/M8.
        foreach (['read', 'write', 'delete', 'manage_retention', 'manage_public_link'] as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'dms', $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'dms'");
        $this->addSql('ALTER TABLE dms_document_public_link DROP FOREIGN KEY FK_DMS_PUBLIC_LINK_VERSION');
        $this->addSql('ALTER TABLE dms_document_public_link DROP FOREIGN KEY FK_DMS_PUBLIC_LINK_DOCUMENT');
        $this->addSql('ALTER TABLE dms_document_public_link DROP FOREIGN KEY FK_DMS_PUBLIC_LINK_CREATED_BY');
        $this->addSql('ALTER TABLE dms_document_version DROP FOREIGN KEY FK_DMS_VERSION_PREVIOUS');
        $this->addSql('ALTER TABLE dms_document_version DROP FOREIGN KEY FK_DMS_VERSION_DOCUMENT');
        $this->addSql('ALTER TABLE dms_document_version DROP FOREIGN KEY FK_DMS_VERSION_UPLOADED_BY');
        $this->addSql('ALTER TABLE dms_document DROP FOREIGN KEY FK_DMS_DOCUMENT_RETENTION_POLICY');
        $this->addSql('ALTER TABLE dms_document DROP FOREIGN KEY FK_DMS_DOCUMENT_CURRENT_VERSION');
        $this->addSql('ALTER TABLE dms_document DROP FOREIGN KEY FK_DMS_DOCUMENT_ETAB');
        $this->addSql('ALTER TABLE dms_document DROP FOREIGN KEY FK_DMS_DOCUMENT_CREATED_BY');
        $this->addSql('DROP TABLE dms_document_public_link');
        $this->addSql('DROP TABLE dms_document_version');
        $this->addSql('DROP TABLE dms_document');
        $this->addSql('DROP TABLE dms_retention_policy');
    }
}
