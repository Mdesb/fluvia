<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module Base de connaissance & Support (plan-support.md §4/§6) — migration de données : insère les
 * permissions du module « support » : `support.{lire,gerer_kb_globale,gerer_categorie,
 * gerer_kb_locale,ouvrir_ticket,lire_ticket_soi,lire_ticket_etablissement,traiter_ticket_n1,
 * traiter_ticket_n2,administrer}`. Idempotente (INSERT IGNORE, même patron que
 * `Version20260817173400` Stock/`Version20260817150200` Personnel).
 */
final class Version20260817205500 extends AbstractMigration
{
    /** @var list<string> */
    private const ACTIONS_SUPPORT = [
        'lire',
        'gerer_kb_globale',
        'gerer_categorie',
        'gerer_kb_locale',
        'ouvrir_ticket',
        'lire_ticket_soi',
        'lire_ticket_etablissement',
        'traiter_ticket_n1',
        'traiter_ticket_n2',
        'administrer',
    ];

    public function getDescription(): string
    {
        return 'Module Support (plan-support.md) : permissions support.{lire,gerer_kb_globale,gerer_categorie,gerer_kb_locale,ouvrir_ticket,lire_ticket_soi,lire_ticket_etablissement,traiter_ticket_n1,traiter_ticket_n2,administrer}.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS_SUPPORT as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'support', $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'support'");
    }
}
