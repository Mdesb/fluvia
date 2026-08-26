<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * ACT-2 — les permissions `lodging.*`, créées **et accordées** (RG-SOCLE-02/03).
 *
 * **Posée dès le premier lot, avant qu'un endpoint n'existe.** C'est la leçon de `App\Stay`, où les
 * permissions n'ont vécu qu'en fixtures pendant plusieurs lots : le module s'annonçait protégé, et il
 * ne l'était nulle part chez un client. Une permission absente ne se voit pas — elle se découvre le
 * jour où quelqu'un construit un menu et retire l'entrée, faute de pouvoir l'accorder à qui que ce soit.
 * Le coût de l'écrire maintenant est nul ; celui de l'oublier se paie en aval.
 *
 * Rattachement par **nom de rôle, à deux noms** — patron de `Version20260824224000` : une base
 * construite uniquement par les migrations ne contient pas forcément les mêmes rôles qu'une base de
 * démonstration, et un identifiant en dur serait juste là où on l'a lu, faux partout ailleurs.
 *
 * ⚠ Écrite à la main, horodatée en **heure locale** (D32). Aucun `DROP`, aucune table touchée hors
 * `sec_permission`/`sec_role_permission`.
 */
final class Version20260826094400 extends AbstractMigration
{
    private const MODULE = 'lodging';

    /** @var list<string> */
    private const ACTIONS = ['read', 'write', 'manage_rates'];

    /** @var list<string> */
    private const ROLES = ['Administrateur groupe', 'Responsable de site'];

    public function getDescription(): string
    {
        return 'Hebergement : permissions lodging.* creees et accordees aux roles administrateur et responsable de site.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), self::MODULE, $action],
            );
        }

        $this->addSql(
            'INSERT IGNORE INTO sec_role_permission (role_id, permission_id) '
            . 'SELECT r.id, p.id FROM sec_role r, sec_permission p '
            . 'WHERE r.nom IN (?, ?) AND p.module = ?',
            [self::ROLES[0], self::ROLES[1], self::MODULE],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rp FROM sec_role_permission rp '
            . 'INNER JOIN sec_permission p ON p.id = rp.permission_id '
            . 'WHERE p.module = ?',
            [self::MODULE],
        );
        $this->addSql('DELETE FROM sec_permission WHERE module = ?', [self::MODULE]);
    }
}
