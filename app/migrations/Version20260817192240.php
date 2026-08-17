<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module API Terminal d'accès & mode dégradé (plan-acces-terminal.md §5) : tables `acces_terminal`
 * (identité machine), `acces_jeton_terminal` (jeton hashé, rotation), `acces_journal_reconciliation`
 * (litige double-consommation, CA-8 — flag applicatif désactivé par défaut, cf.
 * `ValidationPassageHandler`) ; colonne additive `acces_support.version_maj` + index (curseur delta
 * snapshot, US-TERM-03/04) ; séquence native MariaDB `acces_snapshot_seq` (générateur de version,
 * `App\Acces\Service\VersionSnapshotSequencer`) ; permission `acces.snapshot` (idempotente, même
 * patron que `Version20260817150200` Personnel). Réversible (`down` symétrique).
 */
final class Version20260817192240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Module acces-terminal : Terminal/JetonTerminal/JournalReconciliation, Support.versionMaj, séquence acces_snapshot_seq, permission acces.snapshot.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE acces_jeton_terminal (id BINARY(16) NOT NULL, secret_hash VARCHAR(64) NOT NULL, date_emission DATETIME NOT NULL, date_expiration DATETIME DEFAULT NULL, statut VARCHAR(12) DEFAULT \'actif\' NOT NULL, revoque_le DATETIME DEFAULT NULL, terminal_id BINARY(16) NOT NULL, revoque_par_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_A1F5671D852593C (revoque_par_id), INDEX IDX_A1F5671FF631228 (etablissement_id), INDEX idx_jeton_terminal (terminal_id), UNIQUE INDEX uniq_jeton_secret_hash (secret_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_journal_reconciliation (id BINARY(16) NOT NULL, ecart INT NOT NULL, statut VARCHAR(12) DEFAULT \'ouvert\' NOT NULL, horodatage DATETIME NOT NULL, passage_id BINARY(16) NOT NULL, droit_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_87B89DD7FF631228 (etablissement_id), INDEX idx_journal_passage (passage_id), INDEX idx_journal_droit (droit_id), INDEX idx_journal_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE acces_terminal (id BINARY(16) NOT NULL, nom VARCHAR(120) NOT NULL, itbox_ref VARCHAR(128) NOT NULL, statut VARCHAR(12) DEFAULT \'actif\' NOT NULL, dernier_appel DATETIME DEFAULT NULL, dernier_appel_reussi TINYINT DEFAULT NULL, dernier_snapshot_version INT DEFAULT NULL, created_at DATETIME NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX idx_terminal_itbox_ref (itbox_ref), INDEX idx_terminal_etablissement (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE acces_jeton_terminal ADD CONSTRAINT FK_A1F5671E77B6CE8 FOREIGN KEY (terminal_id) REFERENCES acces_terminal (id)');
        $this->addSql('ALTER TABLE acces_jeton_terminal ADD CONSTRAINT FK_A1F5671D852593C FOREIGN KEY (revoque_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE acces_jeton_terminal ADD CONSTRAINT FK_A1F5671FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE acces_journal_reconciliation ADD CONSTRAINT FK_87B89DD7DCC6487D FOREIGN KEY (passage_id) REFERENCES acces_passage (id)');
        $this->addSql('ALTER TABLE acces_journal_reconciliation ADD CONSTRAINT FK_87B89DD75AA93370 FOREIGN KEY (droit_id) REFERENCES acces_droit_acces (id)');
        $this->addSql('ALTER TABLE acces_journal_reconciliation ADD CONSTRAINT FK_87B89DD7FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE acces_terminal ADD CONSTRAINT FK_57D35F9EFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE acces_support ADD version_maj INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_support_version_maj ON acces_support (version_maj)');

        // Générateur de version du snapshot terminal (§1.4 du plan) : séquence native MariaDB (>= 10.3),
        // sans contention de ligne — `App\Acces\Service\VersionSnapshotSequencer`.
        $this->addSql('CREATE SEQUENCE acces_snapshot_seq START WITH 1 INCREMENT BY 1');

        // Permission `acces.snapshot` (§2.1/§3.2 du plan) — idempotente (même patron que
        // Version20260817150200, module Personnel). `acces.ingestion` déjà en usage (Passage/
        // SynchronisationAcces existants) mais absente des migrations de données L3-acces (créée jusqu'ici
        // uniquement par les fixtures) : insérée ici aussi par sécurité, idempotente.
        $this->addSql('INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)', [Uuid::v4()->toBinary(), 'acces', 'snapshot']);
        $this->addSql('INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)', [Uuid::v4()->toBinary(), 'acces', 'ingestion']);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'acces' AND action = 'snapshot'");
        // `acces.ingestion` n'est pas supprimée en down (préexistante à ce lot, réutilisée par le socle
        // L3 — la retirer romprait `/acces/passages`/`/acces/synchro` si elle avait été insérée par une
        // migration antérieure hors de ce fichier).

        $this->addSql('DROP SEQUENCE acces_snapshot_seq');

        $this->addSql('ALTER TABLE acces_jeton_terminal DROP FOREIGN KEY FK_A1F5671E77B6CE8');
        $this->addSql('ALTER TABLE acces_jeton_terminal DROP FOREIGN KEY FK_A1F5671D852593C');
        $this->addSql('ALTER TABLE acces_jeton_terminal DROP FOREIGN KEY FK_A1F5671FF631228');
        $this->addSql('ALTER TABLE acces_journal_reconciliation DROP FOREIGN KEY FK_87B89DD7DCC6487D');
        $this->addSql('ALTER TABLE acces_journal_reconciliation DROP FOREIGN KEY FK_87B89DD75AA93370');
        $this->addSql('ALTER TABLE acces_journal_reconciliation DROP FOREIGN KEY FK_87B89DD7FF631228');
        $this->addSql('ALTER TABLE acces_terminal DROP FOREIGN KEY FK_57D35F9EFF631228');
        $this->addSql('DROP TABLE acces_jeton_terminal');
        $this->addSql('DROP TABLE acces_journal_reconciliation');
        $this->addSql('DROP TABLE acces_terminal');
        $this->addSql('DROP INDEX idx_support_version_maj ON acces_support');
        $this->addSql('ALTER TABLE acces_support DROP version_maj');
    }
}
