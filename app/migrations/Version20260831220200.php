<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Crm\Entity\Client.importBatchRef` (plan-import-i1.md §0.6/§1/§4, T5 — coordination requise, §7
 * point 4 du plan) — **seule migration de ce lot touchant une table existante d'un autre module**,
 * additive, colonne nullable : aucune donnée existante affectée.
 *
 * `?Uuid` nu, **pas** de FK vers `import_batch` (D2 littéral de la spec, §5) : posé une seule fois à la
 * création d'un client par reprise, jamais réécrit par une mise à jour ultérieure.
 */
final class Version20260831220200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Import (I1) : crm_client.import_batch_ref (colonne nue, sans FK, D2).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_client ADD import_batch_ref BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_client_import_batch_ref ON crm_client (import_batch_ref)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_client_import_batch_ref ON crm_client');
        $this->addSql('ALTER TABLE crm_client DROP import_batch_ref');
    }
}
