<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * L5 CRM noyau (M4) — migration de données : crée un `ParametrePmvEtablissement` par établissement
 * existant, valeurs de repli explicites (`rechargeExpireeAutorisee=false`,
 * `regleEcheance={mode:duree_jours,valeur:365}`, `traitementSoldeResiduel=conserve`, §7 plan-crm.md).
 * Idempotente : ignore les établissements déjà paramétrés. Pas de `UUID_TO_BIN` (fonction MySQL 8,
 * absente de MariaDB 11.4) : génération de l'UUID côté PHP, même patron que
 * `Version20260814231500` (permissions compta).
 */
final class Version20260815025500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'L5 CRM noyau (M4) : ParametrePmvEtablissement par défaut pour chaque établissement existant.';
    }

    public function up(Schema $schema): void
    {
        $etablissements = $this->connection->fetchAllAssociative(
            'SELECT e.id FROM org_etablissement e '
            . 'WHERE NOT EXISTS (SELECT 1 FROM crm_parametre_pmv_etablissement p WHERE p.etablissement_id = e.id)'
        );

        foreach ($etablissements as $row) {
            $this->addSql(
                'INSERT INTO crm_parametre_pmv_etablissement '
                . '(id, recharge_expiree_autorisee, regle_echeance, traitement_solde_residuel, etablissement_id) '
                . 'VALUES (?, 0, ?, ?, ?)',
                [Uuid::v4()->toBinary(), '{"mode":"duree_jours","valeur":365}', 'conserve', $row['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Purement des valeurs de repli : pas de restauration d'un état antérieur pertinent.
    }
}
