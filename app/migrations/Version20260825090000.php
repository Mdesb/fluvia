<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module Smart Flow (`App\SmartFlow`) — revue de cohérence : seed de la permission `smart_flow.manage`
 * (paramétrage, déjà déclarée par la spec/le plan — `settingsSchema()`, plan-smart-flow.md §0.11 — mais
 * jamais seedée ni exposée par `SmartFlowModule::permissions()` jusqu'ici, écart signalé en revue).
 *
 * ⚠ Migration écrite à la main (DDL, même précaution que les autres modules de ce dépôt — un seul
 * `INSERT IGNORE` additif sur `sec_permission`, aucune table existante modifiée, patron
 * `Version20260824210100.php`).
 */
final class Version20260825090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Smart Flow : seed de la permission smart_flow.manage (paramétrage, revue de cohérence).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
            [Uuid::v4()->toBinary(), 'smart_flow', 'manage'],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'smart_flow' AND action = 'manage'");
    }
}
