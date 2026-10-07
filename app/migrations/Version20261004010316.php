<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les deux permissions de l'API partenaire, semées par migration (04/10/2026, spec API partenaire v1 §3.1).
 *
 *  - `editor.manage_partner_api` : créer les applications, émettre et révoquer leurs clés — rattachée à
 *    « Éditeur — Direction » et « Administrateur groupe », comme les autres droits de l'éditeur ;
 *  - `api.gerer` : accorder ou retirer l'accès d'une application à SON établissement — rattachée à
 *    « Administrateur groupe ».
 *
 * **Par migration et non seulement par fixture** : les fixtures ne s'exécutent qu'en test et en
 * démonstration ; le seul chemin qui atteint la base d'un client est la migration (voir
 * `Version20260825103000`). Mêmes rattachements que `SocleFixtures`, noms de rôles relevés en préprod le
 * 04/10. Idempotente : rejouée, ou passée après un chargement de fixtures, elle n'ajoute rien.
 *
 * Les rôles à joker (`*.*`) obtiennent ces droits par le joker : c'est attendu, et pour `editor.*` le
 * contrôle de tenant éditeur (`EditorOnly`, 404) passe avant la permission.
 */
final class Version20261004010316 extends AbstractMigration
{
    /** [module, action, rôles] */
    private const PERMISSIONS = [
        ['editor', 'manage_partner_api', ['Éditeur — Direction', 'Administrateur groupe']],
        ['api', 'gerer', ['Administrateur groupe']],
    ];

    public function getDescription(): string
    {
        return 'API partenaire : permissions `editor.manage_partner_api` et `api.gerer`, et leurs rôles';
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
