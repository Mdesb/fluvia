<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * L4 Comptabilité & Régie (M6) — migration de données : insère les permissions du module « compta »
 * (RG-SOCLE-02, dérivées du tableau Acteurs & droits de `spec-compta.md` §3) et l'action
 * `caisse.versement` (complète le référentiel `caisse` de M2 pour le régisseur, §10 du plan-compta).
 * Insertion idempotente (INSERT IGNORE, couple module/action unique). L'affectation aux rôles relève
 * de M8 / des fixtures.
 *
 * ⚠ HYPOTHÈSE (plan §14, point ouvert n°1) : `compta.lire_rad`/`compta.lire_consolide` sont des
 * propositions non nommées littéralement dans les sources, à figer avec M8.
 */
final class Version20260814231500 extends AbstractMigration
{
    /** @var list<string> */
    private const ACTIONS_COMPTA = ['lire', 'lettrer', 'valider', 'exporter', 'cloturer', 'gerer', 'lire_rad', 'lire_consolide'];

    public function getDescription(): string
    {
        return 'L4 (Comptabilité & Régie) : permissions compta.{lire,lettrer,valider,exporter,cloturer,gerer,lire_rad,lire_consolide} + caisse.versement.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS_COMPTA as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'compta', $action],
            );
        }
        $this->addSql(
            'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
            [Uuid::v4()->toBinary(), 'caisse', 'versement'],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'compta'");
        $this->addSql("DELETE FROM sec_permission WHERE module = 'caisse' AND action = 'versement'");
    }
}
