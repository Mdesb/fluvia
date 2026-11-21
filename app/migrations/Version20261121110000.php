<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot D3-B de #365 — la permission `payment.configure` (saisir la clé Stripe de l'exploitant), semée
 * par migration et rattachée au seul rôle « Administrateur groupe » (l'administrateur socle).
 *
 * **Par migration et non seulement par fixture** : les fixtures ne s'exécutent qu'en test et en
 * démonstration ; le seul chemin qui atteint la base d'un client est la migration (voir
 * `Version20261004010316`, dont ceci est la copie). Idempotente : rejouée, ou passée après un
 * chargement de fixtures (`ComptaFixtures`), elle n'ajoute rien. Requêtes paramétrées.
 *
 * Les rôles à joker (`*.*`) obtiennent le droit par le joker : c'est attendu.
 */
final class Version20261121110000 extends AbstractMigration
{
    public const MODULE = 'payment';
    public const ACTION = 'configure';
    public const ROLE = 'Administrateur groupe';

    public function getDescription(): string
    {
        return 'D3-B #365 : permission `payment.configure`, rattachée à « Administrateur groupe »';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO sec_permission (id, module, action)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), :module, :action FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM sec_permission WHERE module = :module AND action = :action)
            SQL, ['module' => self::MODULE, 'action' => self::ACTION]);

        $this->addSql(<<<'SQL'
            INSERT INTO sec_role_permission (role_id, permission_id)
            SELECT r.id, p.id FROM sec_role r JOIN sec_permission p ON p.module = :module AND p.action = :action
            WHERE r.nom = :role
              AND NOT EXISTS (SELECT 1 FROM sec_role_permission rp WHERE rp.role_id = r.id AND rp.permission_id = p.id)
            SQL, ['module' => self::MODULE, 'action' => self::ACTION, 'role' => self::ROLE]);
    }

    public function down(Schema $schema): void
    {
        // Les rattachements d'abord : une permission supprimée laisserait des lignes orphelines.
        $this->addSql(<<<'SQL'
            DELETE rp FROM sec_role_permission rp
              JOIN sec_permission p ON p.id = rp.permission_id
             WHERE p.module = :module AND p.action = :action
            SQL, ['module' => self::MODULE, 'action' => self::ACTION]);
        $this->addSql('DELETE FROM sec_permission WHERE module = :module AND action = :action', ['module' => self::MODULE, 'action' => self::ACTION]);
    }
}
