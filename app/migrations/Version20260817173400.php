<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module Stock & Inventaire boutique (plan-stock.md §6/§5) — migration de données : insère les
 * permissions du module « stock » : `stock.{gerer_article,gerer_fournisseur,gerer_achat,receptionner,
 * ajuster,inventorier,transferer,lire,gerer,valider_ecart,parametrer,lire_valorisation}`. Idempotente
 * (INSERT IGNORE, même patron que `Version20260817150200` Personnel/`Version20260816162500` Reporting).
 */
final class Version20260817173400 extends AbstractMigration
{
    /** @var list<string> */
    private const ACTIONS_STOCK = [
        'gerer_article',
        'gerer_fournisseur',
        'gerer_achat',
        'receptionner',
        'ajuster',
        'inventorier',
        'transferer',
        'lire',
        'gerer',
        'valider_ecart',
        'parametrer',
        'lire_valorisation',
    ];

    public function getDescription(): string
    {
        return 'Module Stock (plan-stock.md) : permissions stock.{gerer_article,gerer_fournisseur,gerer_achat,receptionner,ajuster,inventorier,transferer,lire,gerer,valider_ecart,parametrer,lire_valorisation}.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS_STOCK as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'stock', $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'stock'");
    }
}
