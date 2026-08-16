<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module M7 Reporting (L11) — migration de données : insère les permissions du module « reporting »
 * (RG-SOCLE-02, tableau Acteurs & droits §3 spec-reporting.md) : `reporting.lire`,
 * `reporting.planifier`, `reporting.configurer`. Idempotente (INSERT IGNORE, même patron que
 * `Version20260816112500` Boutique).
 */
final class Version20260816162500 extends AbstractMigration
{
    /** @var list<string> */
    private const ACTIONS_REPORTING = ['lire', 'planifier', 'configurer'];

    public function getDescription(): string
    {
        return 'M7 Reporting (L11) : permissions reporting.{lire,planifier,configurer}.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS_REPORTING as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'reporting', $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'reporting'");
    }
}
