<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module Autorisations graduées des opérations sensibles (`App\Autorisation`, plan-autorisation.md
 * §7) — migration retaillée à la main (contexte multi-agents, base de test partagée) pour ne porter
 * QUE le schéma/les données de ce module : `atz_operation_sensible` (catalogue paramétrable,
 * RG-AUTZ-01), `atz_limite_autorisation` (RG-AUTZ-02), `atz_demande_escalade` (RG-AUTZ-06/07) ;
 * permissions `autorisation.{gerer,approuver,lire}` ; seed du catalogue (`vente.annuler`/
 * `vente.rembourser` câblés v1, + 4 opérations déclarées non câblées, §7.3 plan). Suppose les
 * migrations socle (L0, `sec_role`/`sec_utilisateur`/`org_etablissement`) déjà jouées.
 */
final class Version20260818120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Autorisation : atz_operation_sensible/atz_limite_autorisation/atz_demande_escalade, '
            . 'permissions autorisation.{gerer,approuver,lire}, seed catalogue (vente.annuler/vente.rembourser + 4 non câblées).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE atz_operation_sensible (code VARCHAR(80) NOT NULL, libelle VARCHAR(180) NOT NULL, module_action VARCHAR(120) NOT NULL, active TINYINT DEFAULT 1 NOT NULL, PRIMARY KEY (code)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE atz_limite_autorisation (id BINARY(16) NOT NULL, operation_code VARCHAR(80) NOT NULL, role_id BINARY(16) DEFAULT NULL, utilisateur_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, plafond_montant NUMERIC(10, 2) DEFAULT NULL, perimetre VARCHAR(20) NOT NULL, escalade_au_dela TINYINT DEFAULT 0 NOT NULL, cumul_journalier_max NUMERIC(10, 2) DEFAULT NULL, date_creation DATETIME NOT NULL, auteur_id BINARY(16) NOT NULL, INDEX idx_limite_operation (operation_code), INDEX idx_limite_etablissement (etablissement_id), INDEX IDX_D018DC96D60322AC (role_id), INDEX IDX_D018DC96FB88E14F (utilisateur_id), INDEX IDX_D018DC9660BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE atz_demande_escalade (id BINARY(16) NOT NULL, operation_code VARCHAR(80) NOT NULL, cible_type VARCHAR(60) NOT NULL, cible_id VARCHAR(36) NOT NULL, montant NUMERIC(10, 2) NOT NULL, auteur_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, statut VARCHAR(12) NOT NULL, date_demande DATETIME NOT NULL, date_expiration DATETIME NOT NULL, superviseur_id BINARY(16) DEFAULT NULL, date_traitement DATETIME DEFAULT NULL, motif_rejet VARCHAR(255) DEFAULT NULL, jeton BINARY(16) NOT NULL, date_rejeu DATETIME DEFAULT NULL, INDEX idx_escalade_operation_cible (operation_code, cible_id), INDEX idx_escalade_auteur (auteur_id), INDEX idx_escalade_etablissement (etablissement_id), INDEX idx_escalade_etablissement_statut (etablissement_id, statut), INDEX idx_escalade_statut_expiration (statut, date_expiration), INDEX IDX_27BD2ADFB7BB80FF (superviseur_id), UNIQUE INDEX UNIQ_27BD2ADF2CF647B (jeton), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE atz_limite_autorisation ADD CONSTRAINT FK_ATZ_LIMITE_OPERATION FOREIGN KEY (operation_code) REFERENCES atz_operation_sensible (code)');
        $this->addSql('ALTER TABLE atz_limite_autorisation ADD CONSTRAINT FK_ATZ_LIMITE_ROLE FOREIGN KEY (role_id) REFERENCES sec_role (id)');
        $this->addSql('ALTER TABLE atz_limite_autorisation ADD CONSTRAINT FK_ATZ_LIMITE_UTILISATEUR FOREIGN KEY (utilisateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE atz_limite_autorisation ADD CONSTRAINT FK_ATZ_LIMITE_ETABLISSEMENT FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE atz_limite_autorisation ADD CONSTRAINT FK_ATZ_LIMITE_AUTEUR FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');

        $this->addSql('ALTER TABLE atz_demande_escalade ADD CONSTRAINT FK_ATZ_ESCALADE_OPERATION FOREIGN KEY (operation_code) REFERENCES atz_operation_sensible (code)');
        $this->addSql('ALTER TABLE atz_demande_escalade ADD CONSTRAINT FK_ATZ_ESCALADE_AUTEUR FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE atz_demande_escalade ADD CONSTRAINT FK_ATZ_ESCALADE_ETABLISSEMENT FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE atz_demande_escalade ADD CONSTRAINT FK_ATZ_ESCALADE_SUPERVISEUR FOREIGN KEY (superviseur_id) REFERENCES sec_utilisateur (id)');

        // Permissions (idempotent, même patron que Version20260817173400 Stock).
        foreach (['gerer', 'approuver', 'lire'] as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'autorisation', $action],
            );
        }

        // Seed du catalogue (§7.3 plan) : câblées v1 + déclarées non câblées (extensibilité,
        // aucun effet observable tant qu'aucun handler n'appelle ServiceAutorisation).
        $catalogue = [
            ['vente.annuler', 'Vente — Annulation', 'vente.annuler'],
            ['vente.rembourser', 'Vente — Remboursement', 'vente.rembourser'],
            ['caution.retenue', 'Caution — Retenue', 'caution.gerer'],
            ['compta.detaxe', 'Comptabilité — Détaxe', 'compta.gerer'],
            ['vente.remise_exceptionnelle', 'Vente — Remise exceptionnelle', 'vente.forcer_prix'],
            ['caisse.reouverture_session', 'Caisse — Réouverture de session sécurisée', 'caisse.ouvrir'],
        ];
        foreach ($catalogue as [$code, $libelle, $moduleAction]) {
            $this->addSql(
                'INSERT IGNORE INTO atz_operation_sensible (code, libelle, module_action, active) VALUES (?, ?, ?, 1)',
                [$code, $libelle, $moduleAction],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM atz_operation_sensible WHERE code IN ('vente.annuler', 'vente.rembourser', 'caution.retenue', 'compta.detaxe', 'vente.remise_exceptionnelle', 'caisse.reouverture_session')");
        $this->addSql("DELETE FROM sec_permission WHERE module = 'autorisation'");

        $this->addSql('ALTER TABLE atz_limite_autorisation DROP FOREIGN KEY FK_ATZ_LIMITE_OPERATION');
        $this->addSql('ALTER TABLE atz_limite_autorisation DROP FOREIGN KEY FK_ATZ_LIMITE_ROLE');
        $this->addSql('ALTER TABLE atz_limite_autorisation DROP FOREIGN KEY FK_ATZ_LIMITE_UTILISATEUR');
        $this->addSql('ALTER TABLE atz_limite_autorisation DROP FOREIGN KEY FK_ATZ_LIMITE_ETABLISSEMENT');
        $this->addSql('ALTER TABLE atz_limite_autorisation DROP FOREIGN KEY FK_ATZ_LIMITE_AUTEUR');

        $this->addSql('ALTER TABLE atz_demande_escalade DROP FOREIGN KEY FK_ATZ_ESCALADE_OPERATION');
        $this->addSql('ALTER TABLE atz_demande_escalade DROP FOREIGN KEY FK_ATZ_ESCALADE_AUTEUR');
        $this->addSql('ALTER TABLE atz_demande_escalade DROP FOREIGN KEY FK_ATZ_ESCALADE_ETABLISSEMENT');
        $this->addSql('ALTER TABLE atz_demande_escalade DROP FOREIGN KEY FK_ATZ_ESCALADE_SUPERVISEUR');

        $this->addSql('DROP TABLE atz_demande_escalade');
        $this->addSql('DROP TABLE atz_limite_autorisation');
        $this->addSql('DROP TABLE atz_operation_sensible');
    }
}
