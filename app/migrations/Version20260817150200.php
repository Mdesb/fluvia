<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module Personnel & planning d'équipe (plan-personnel.md §4/§5.2) — migration de données : insère
 * les permissions du module « personnel » (RG-SOCLE-02, tableau §4 du plan) :
 * `personnel.{gerer_employe,gerer_qualification,gerer_badge,gerer_planning,valider_absence,lire,
 * lire_soi,declarer_absence_soi}`. Idempotente (INSERT IGNORE, même patron que `Version20260816162500`
 * Reporting/`Version20260816112500` Boutique).
 */
final class Version20260817150200 extends AbstractMigration
{
    /** @var list<string> */
    private const ACTIONS_PERSONNEL = [
        'gerer_employe',
        'gerer_qualification',
        'gerer_badge',
        'gerer_planning',
        'valider_absence',
        'lire',
        'lire_soi',
        'declarer_absence_soi',
    ];

    public function getDescription(): string
    {
        return 'Module Personnel (plan-personnel.md) : permissions personnel.{gerer_employe,gerer_qualification,gerer_badge,gerer_planning,valider_absence,lire,lire_soi,declarer_absence_soi}.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS_PERSONNEL as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'personnel', $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'personnel'");
    }
}
