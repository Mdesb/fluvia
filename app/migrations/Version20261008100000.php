<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le rôle « Client final » ne porte plus `crm.pmv_recharger_soi`.
 *
 * `POST /clients/{id}/pmv/recharger` créditait le montant envoyé sans aucun paiement, et ce solde se
 * dépense à toutes les caisses du groupe. Aucun paiement en ligne n'est branché (bouchons, D112) :
 * la recharge « soi » n'a rien de réel derrière elle. Le processeur la refuse aussi ; la permission
 * reste au référentiel pour le jour où un paiement réel existera.
 *
 * Écrite à la main, horodatée en heure locale (D32).
 */
final class Version20261008100000 extends AbstractMigration
{
    private const ROLE = 'Client final';

    public function getDescription(): string
    {
        return 'Retire crm.pmv_recharger_soi du rôle « Client final » (recharge sans paiement).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE rp FROM sec_role_permission rp
              JOIN sec_role r ON r.id = rp.role_id
              JOIN sec_permission p ON p.id = rp.permission_id
             WHERE r.nom = ? AND p.module = 'crm' AND p.action = 'pmv_recharger_soi'
            SQL, [self::ROLE]);
    }

    public function down(Schema $schema): void
    {
        // Rouvre la recharge sans paiement : ne descendre que pour revenir au code d'avant.
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO sec_role_permission (role_id, permission_id)
            SELECT r.id, p.id FROM sec_role r, sec_permission p
             WHERE r.nom = ? AND p.module = 'crm' AND p.action = 'pmv_recharger_soi'
            SQL, [self::ROLE]);
    }
}
