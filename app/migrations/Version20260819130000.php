<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Service transverse `App\Ocr` (FIN-0, spec-ocr.md/plan-ocr.md) — migration additive, aucune
 * dépendance destructive : 2 tables, `ocr_provider_config` (configuration par établissement,
 * RG-OCR-06 — 1 par établissement, clé API stockée chiffrée) et `ocr_extraction_attempt`
 * (traçabilité append-only de chaque tentative d'extraction, RG-OCR-04).
 *
 * Nommage anglais (identifiants techniques : tables, colonnes) — écart assumé par rapport aux tables
 * `sepa_*`/`devis_*` historiquement nommées en français, alignement sur la consigne « Anglais partout »
 * de ce lot FIN-0 (contrairement à la prose des specs/plans, restée en français).
 *
 * SQL généré via `doctrine:migrations:diff` contre les entités `App\Ocr\Entity\OcrProviderConfig`/
 * `ExtractionAttempt` (extrait manuellement pour ne conserver **que** ces 2 tables — plusieurs autres
 * agents travaillent en parallèle sur ce dépôt avec des entités non encore migrées ; le diff brut aurait
 * inclus leurs tables, hors périmètre de ce lot).
 */
final class Version20260819130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Service OCR (App\\Ocr) : tables ocr_provider_config et ocr_extraction_attempt (config par établissement + traçabilité).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ocr_extraction_attempt (
                id BINARY(16) NOT NULL,
                document_kind VARCHAR(20) NOT NULL,
                provider VARCHAR(30) NOT NULL,
                status VARCHAR(20) NOT NULL,
                confidence_score DOUBLE PRECISION DEFAULT NULL,
                extracted_fields JSON DEFAULT NULL,
                requested_at DATETIME NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                requested_by_id BINARY(16) DEFAULT NULL,
                INDEX IDX_161D18F8565851 (establishment_id),
                INDEX IDX_161D18F4DA1E751 (requested_by_id),
                INDEX IDX_OCR_ATTEMPT_ETAB_STATUS (establishment_id, status),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE ocr_provider_config (
                id BINARY(16) NOT NULL,
                provider VARCHAR(16) DEFAULT 'manual' NOT NULL,
                api_key_encrypted LONGTEXT DEFAULT NULL,
                confidence_threshold NUMERIC(3, 2) DEFAULT '0.70' NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_ocr_provider_config_establishment (establishment_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql('ALTER TABLE ocr_extraction_attempt ADD CONSTRAINT FK_161D18F8565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE ocr_extraction_attempt ADD CONSTRAINT FK_161D18F4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE ocr_provider_config ADD CONSTRAINT FK_5D05EE4E8565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ocr_extraction_attempt DROP FOREIGN KEY FK_161D18F8565851');
        $this->addSql('ALTER TABLE ocr_extraction_attempt DROP FOREIGN KEY FK_161D18F4DA1E751');
        $this->addSql('ALTER TABLE ocr_provider_config DROP FOREIGN KEY FK_5D05EE4E8565851');
        $this->addSql('DROP TABLE ocr_extraction_attempt');
        $this->addSql('DROP TABLE ocr_provider_config');
    }
}
