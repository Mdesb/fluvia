<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * ACT-3 — les permissions `stay.*`, créées **et accordées** (RG-SOCLE-02/03).
 *
 * **Pourquoi une migration alors que les fixtures les posaient déjà.** Les fixtures ne tournent jamais
 * chez un client : elles servent la démonstration et les tests. Mon module exposait donc une API
 * protégée par des permissions qui, en production, n'existaient pas — `claude-H` l'a constaté en
 * construisant le menu et a retiré l'entrée « séjour » plutôt que de l'annoncer à des gens qui
 * n'auraient rien pu en faire. Elle a eu raison.
 *
 * **Et pourquoi le rattachement compte autant que la création.** Une permission qu'aucun rôle ne
 * détient protège aussi bien qu'un mur sans porte : personne ne peut franchir, y compris ceux qui le
 * devraient. Le précédent `smart_flow` (`Version20260824110000`) crée les siennes sans les accorder —
 * même symptôme, module différent ; je le signale à l'intégrateur plutôt que de le recopier.
 *
 * **Rattachement par nom, et à deux noms** — patron de `Version20260824224000` (`claude-H`) : une base
 * construite uniquement par les migrations ne contient pas « Administrateur groupe », qui vient des
 * fixtures ; elle contient « Responsable de site », le rôle modèle installé par le socle. Viser les
 * deux évite d'avoir à savoir laquelle des deux bases on migre. Un identifiant de rôle en dur serait
 * juste sur la base où on l'a lu, et faux partout ailleurs.
 *
 * ⚠ Écrite à la main et horodatée en **heure locale** (D32) : le conteneur PHP tourne en UTC, deux
 * heures derrière, et une migration horodatée là-bas se classerait avant des migrations déjà
 * appliquées. Aucun `DROP`, aucune table touchée hors `sec_permission`/`sec_role_permission`.
 */
final class Version20260825235100 extends AbstractMigration
{
    private const MODULE = 'stay';

    /** @var list<string> */
    private const ACTIONS = ['read', 'write', 'charge', 'settle'];

    /** @var list<string> */
    private const ROLES = ['Administrateur groupe', 'Responsable de site'];

    public function getDescription(): string
    {
        return 'Sejour : permissions stay.* creees et accordees aux roles administrateur et responsable de site.';
    }

    public function up(Schema $schema): void
    {
        // `INSERT IGNORE` : le couple (module, action) est unique (`uniq_permission_module_action`).
        // La migration est donc rejouable, et cohabite avec les fixtures qui posent les mêmes lignes
        // sur une base de démonstration.
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
        // Les rattachements d'abord : la contrainte de jointure référence les permissions.
        $this->addSql(
            'DELETE rp FROM sec_role_permission rp '
            . 'INNER JOIN sec_permission p ON p.id = rp.permission_id '
            . 'WHERE p.module = ?',
            [self::MODULE],
        );
        $this->addSql('DELETE FROM sec_permission WHERE module = ?', [self::MODULE]);
    }
}
