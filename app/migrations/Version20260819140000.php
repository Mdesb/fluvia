<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Compta` (lot FIN-1, extension additive) — RG-M6-13/RG-M6-14 :
 * - `compta_ligne_ecriture` : 3 colonnes nullable pour le compte auxiliaire / tiers (`counterparty*`,
 *   référence logique, pas de FK dure — même patron que `MappingComptable.categorie`) + index sur
 *   `counterparty_id`.
 * - `compta_lettrage_ecriture` : 1 colonne nullable `reconciliation_code`, partagée entre les lignes
 *   d'un même lettrage groupé (RG-M6-14) + index.
 *
 * Aucune contrainte NOT NULL, aucune suppression, aucune donnée existante affectée : les lignes/
 * lettrages déjà en base héritent de NULL sur les 4 colonnes (CA-7, non-régression NF525/FEC).
 */
final class Version20260819140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Compta (FIN-1) : compte auxiliaire/tiers sur LigneEcriture + reconciliationCode sur LettrageEcriture (colonnes additives nullable).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_ligne_ecriture ADD counterparty_type VARCHAR(32) DEFAULT NULL, ADD counterparty_id BINARY(16) DEFAULT NULL, ADD counterparty_label VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ligne_ecriture_counterparty ON compta_ligne_ecriture (counterparty_id)');
        $this->addSql('ALTER TABLE compta_lettrage_ecriture ADD reconciliation_code VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_lettrage_reconciliation_code ON compta_lettrage_ecriture (reconciliation_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_lettrage_reconciliation_code ON compta_lettrage_ecriture');
        $this->addSql('ALTER TABLE compta_lettrage_ecriture DROP reconciliation_code');
        $this->addSql('DROP INDEX idx_ligne_ecriture_counterparty ON compta_ligne_ecriture');
        $this->addSql('ALTER TABLE compta_ligne_ecriture DROP counterparty_type, DROP counterparty_id, DROP counterparty_label');
    }
}
