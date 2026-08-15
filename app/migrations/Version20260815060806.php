<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * L6 Verticale Piscine — migration de données : insère les permissions du module « piscine »
 * (RG-SOCLE-02, dérivées du tableau Acteurs & droits de la spec §3). Le couple (module, action) est
 * unique ; insertion idempotente (INSERT IGNORE). L'affectation aux rôles relève de l'administration
 * (M8) / des fixtures.
 *
 * ⚠ HYPOTHÈSE (plan §4, point ouvert n°10) : les noms de permissions `piscine.{configurer,
 * gerer_casier, forcer_casier, lire, gerer}` dérivent du tableau Acteurs & droits mais ne sont pas
 * nommés littéralement dans les sources ; à figer avec M8 (comme `acces.*` en L3).
 */
final class Version20260815060806 extends AbstractMigration
{
    /** @var list<string> */
    private const ACTIONS = ['configurer', 'gerer_casier', 'forcer_casier', 'lire', 'gerer'];

    public function getDescription(): string
    {
        return 'L6 Piscine : permissions piscine.{configurer,gerer_casier,forcer_casier,lire,gerer}.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'piscine', $action]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'piscine'");
    }
}
