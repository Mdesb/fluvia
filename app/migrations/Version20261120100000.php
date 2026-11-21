<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * NF525 : la permission `editor.nf525_alerts` (20/11/2026, #370, étape 11), semée par migration.
 *
 * Elle donne accès, côté éditeur, aux alertes « chaîne fiscale bloquée » de tous les établissements
 * (détail technique compris) et à la note. Rattachée à « Éditeur — Direction », « Éditeur — Assistance »
 * et « Administrateur groupe » (décision de Maxime, 20/11), comme `SocleFixtures`.
 *
 * **Par migration et non seulement par fixture** : les fixtures ne s'exécutent qu'en test et en
 * démonstration ; le seul chemin qui atteint la base d'un client est la migration (voir
 * `Version20261004010316`, dont celle-ci reprend le patron). Idempotente : rejouée, ou passée après un
 * chargement de fixtures, elle n'ajoute rien. Un rôle absent de la base n'est pas une erreur : la
 * jointure ne rattache rien.
 */
final class Version20261120100000 extends AbstractMigration
{
    /** [module, action, rôles] */
    private const PERMISSIONS = [
        ['editor', 'nf525_alerts', ['Éditeur — Direction', 'Éditeur — Assistance', 'Administrateur groupe']],
    ];

    public function getDescription(): string
    {
        return 'NF525 : permission `editor.nf525_alerts` et ses rôles';
    }

    public function up(Schema $schema): void
    {
        foreach (self::PERMISSIONS as [$module, $action, $roles]) {
            $this->addSql(<<<'SQL'
                INSERT INTO sec_permission (id, module, action)
                SELECT UNHEX(REPLACE(UUID(), '-', '')), :module, :action FROM DUAL
                WHERE NOT EXISTS (SELECT 1 FROM sec_permission WHERE module = :module AND action = :action)
                SQL, ['module' => $module, 'action' => $action]);

            foreach ($roles as $role) {
                $this->addSql(<<<'SQL'
                    INSERT INTO sec_role_permission (role_id, permission_id)
                    SELECT r.id, p.id FROM sec_role r JOIN sec_permission p ON p.module = :module AND p.action = :action
                    WHERE r.nom = :role
                      AND NOT EXISTS (SELECT 1 FROM sec_role_permission rp WHERE rp.role_id = r.id AND rp.permission_id = p.id)
                    SQL, ['module' => $module, 'action' => $action, 'role' => $role]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::PERMISSIONS as [$module, $action]) {
            // Les rattachements d'abord : une permission supprimée laisserait des lignes orphelines.
            $this->addSql(<<<'SQL'
                DELETE rp FROM sec_role_permission rp
                  JOIN sec_permission p ON p.id = rp.permission_id
                 WHERE p.module = :module AND p.action = :action
                SQL, ['module' => $module, 'action' => $action]);
            $this->addSql('DELETE FROM sec_permission WHERE module = :module AND action = :action', ['module' => $module, 'action' => $action]);
        }
    }
}
