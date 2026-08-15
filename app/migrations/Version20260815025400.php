<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * L5 CRM noyau (M4) — migration de données : insère les permissions du module « crm » (RG-SOCLE-02,
 * dérivées du tableau Acteurs & droits de `spec-crm.md` §3). Insertion idempotente (INSERT IGNORE,
 * couple module/action unique). L'affectation aux rôles relève de M8 / des fixtures.
 */
final class Version20260815025400 extends AbstractMigration
{
    /** @var list<string> */
    private const ACTIONS_CRM = [
        'lire', 'lire_soi', 'creer', 'modifier', 'modifier_soi',
        'pmv_lire', 'pmv_lire_soi', 'pmv_recharger', 'pmv_recharger_soi',
        'famille_gerer', 'consentement_gerer_soi', 'rgpd_demander', 'rgpd_gerer',
        'fusionner', 'parametrer', 'exporter', 'segment_gerer',
    ];

    public function getDescription(): string
    {
        return 'L5 CRM noyau (M4) : permissions crm.* (RG-SOCLE-02, §6 plan-crm.md).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS_CRM as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'crm', $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'crm'");
    }
}
