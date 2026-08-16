<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module M3 Boutique en ligne — migration de données : insère les permissions du module « boutique »
 * (RG-SOCLE-02, tableau Acteurs & droits §3 spec-boutique.md) et crée le rôle système
 * `RoleClientFinal` (bundle de permissions `_soi`, §1.4/T2 plan-boutique.md). Idempotent (INSERT
 * IGNORE / vérifie l'existence avant insertion), même patron que `Version20260815114200` (Sport) et
 * `Version20260815092253` (rôles-modèles L7).
 */
final class Version20260816112500 extends AbstractMigration
{
    public const ROLE_CLIENT_FINAL_NOM = 'Client final';

    /** @var list<string> */
    private const ACTIONS_BOUTIQUE = [
        'gerer_vitrine', 'gerer_promo', 'gerer_connecteur_ota', 'lire', 'traiter_remboursement',
        'traiter_retrait', 'gerer', 'acheter_soi', 'lire_soi', 'gerer_famille_soi', 'demander_remboursement_soi',
    ];

    /** @var list<array{0: string, 1: string}> Bundle RoleClientFinal (§1.4 du plan). */
    private const PERMISSIONS_CLIENT_FINAL = [
        ['crm', 'lire_soi'], ['crm', 'modifier_soi'], ['crm', 'pmv_lire_soi'], ['crm', 'pmv_recharger_soi'],
        ['crm', 'consentement_gerer_soi'], ['reservation', 'reserver_soi'], ['reservation', 'lire_soi'],
        ['reservation', 'annuler_soi'], ['boutique', 'acheter_soi'], ['boutique', 'lire_soi'],
        ['boutique', 'gerer_famille_soi'], ['boutique', 'demander_remboursement_soi'],
    ];

    public function getDescription(): string
    {
        return 'Module M3 Boutique : permissions boutique.{gerer_vitrine,gerer_promo,gerer_connecteur_ota,'
            . 'lire,traiter_remboursement,traiter_retrait,gerer,acheter_soi,lire_soi,gerer_famille_soi,'
            . 'demander_remboursement_soi} + rôle système « Client final » (RoleClientFinal, §0 décision n°3).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS_BOUTIQUE as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'boutique', $action],
            );
        }

        // RoleClientFinal : idempotent via requête PHP (sec_role.nom porte une contrainte unique,
        // même garde que Version20260815092253).
        $roleExiste = (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM sec_role WHERE nom = ?',
            [self::ROLE_CLIENT_FINAL_NOM],
        );
        if (!$roleExiste) {
            $roleId = Uuid::v4()->toBinary();
            $this->addSql(
                'INSERT INTO sec_role (id, nom, est_modele, role_modele_origine_id) VALUES (?, ?, 0, NULL)',
                [$roleId, self::ROLE_CLIENT_FINAL_NOM],
            );

            foreach (self::PERMISSIONS_CLIENT_FINAL as [$module, $action]) {
                $permissionId = $this->connection->fetchOne(
                    'SELECT id FROM sec_permission WHERE module = ? AND action = ?',
                    [$module, $action],
                );
                if ($permissionId === false) {
                    $permissionId = Uuid::v4()->toBinary();
                    $this->addSql(
                        'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                        [$permissionId, $module, $action],
                    );
                }
                $this->addSql(
                    'INSERT IGNORE INTO sec_role_permission (role_id, permission_id) VALUES (?, ?)',
                    [$roleId, $permissionId],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf(
            "DELETE sec_role_permission FROM sec_role_permission INNER JOIN sec_role ON sec_role.id = sec_role_permission.role_id WHERE sec_role.nom = '%s'",
            self::ROLE_CLIENT_FINAL_NOM,
        ));
        $this->addSql(sprintf("DELETE FROM sec_role WHERE nom = '%s'", self::ROLE_CLIENT_FINAL_NOM));
        $this->addSql("DELETE FROM sec_permission WHERE module = 'boutique'");
    }
}
