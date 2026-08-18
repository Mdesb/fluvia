<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module Facturation (`plan-facturation.md` §4, migration 2 — donnée M6 additive) : crée le journal
 * `FAC` (« Journal des factures directes ») pour chaque `compta_profil_exploitant` existant, réutilisé
 * par `App\Facturation\Service\EmettreFactureDirecteHandler` (§0.2 du plan). **Aucune table ni colonne
 * `App\Compta\*` n'est modifiée** — une seule INSERT de données dans le plan de comptes existant.
 *
 * Note (§7 point 6 du plan) : un profil créé après cette migration verrait normalement son journal
 * `FAC` manquant ; `App\Facturation\Service\ResolveurComptesFacturation::journalFactures()` le crée
 * alors paresseusement (filet de sécurité applicatif, cf. commentaire de cette classe).
 */
final class Version20260818080750 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Module Facturation : journal comptable 'FAC' (factures directes) pour chaque profil exploitant existant.";
    }

    public function up(Schema $schema): void
    {
        $profils = $this->connection->fetchFirstColumn('SELECT id FROM compta_profil_exploitant');
        foreach ($profils as $profilId) {
            $this->addSql(
                'INSERT IGNORE INTO compta_journal (id, code, libelle, profil_exploitant_id) VALUES (?, ?, ?, ?)',
                [Uuid::v4()->toBinary(), 'FAC', 'Journal des factures directes', $profilId],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM compta_journal WHERE code = 'FAC'");
    }
}
