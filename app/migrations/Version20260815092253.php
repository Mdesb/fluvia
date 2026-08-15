<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260815092253 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'L7 back-office & droits (M8) : cycle de vie utilisateur (statut/invitation/MFA/tokenVersion), '
            . 'DelegationDroit, JetonReinitialisation, Role.estModele/roleModeleOrigine, EntreeAudit avant/après.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sec_delegation_droit (id BINARY(16) NOT NULL, date_debut DATETIME NOT NULL, date_fin DATETIME NOT NULL, statut VARCHAR(12) NOT NULL, motif_revocation VARCHAR(255) DEFAULT NULL, date_revocation DATETIME DEFAULT NULL, date_creation DATETIME NOT NULL, delegant_id BINARY(16) NOT NULL, beneficiaire_id BINARY(16) NOT NULL, role_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, revoque_par_id BINARY(16) DEFAULT NULL, INDEX IDX_726DBC1548FE0B5C (delegant_id), INDEX IDX_726DBC155AF81F68 (beneficiaire_id), INDEX IDX_726DBC15D60322AC (role_id), INDEX IDX_726DBC15FF631228 (etablissement_id), INDEX IDX_726DBC15D852593C (revoque_par_id), INDEX idx_delegation_beneficiaire_etab_statut (beneficiaire_id, etablissement_id, statut), INDEX idx_delegation_statut_date_fin (statut, date_fin), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sec_jeton_reinitialisation (id BINARY(16) NOT NULL, jeton VARCHAR(255) NOT NULL, date_expiration DATETIME NOT NULL, utilise TINYINT DEFAULT 0 NOT NULL, date_creation DATETIME NOT NULL, utilisateur_id BINARY(16) NOT NULL, INDEX IDX_C249465EFB88E14F (utilisateur_id), UNIQUE INDEX uniq_jeton_reinitialisation (jeton), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sec_delegation_droit ADD CONSTRAINT FK_726DBC1548FE0B5C FOREIGN KEY (delegant_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE sec_delegation_droit ADD CONSTRAINT FK_726DBC155AF81F68 FOREIGN KEY (beneficiaire_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE sec_delegation_droit ADD CONSTRAINT FK_726DBC15D60322AC FOREIGN KEY (role_id) REFERENCES sec_role (id)');
        $this->addSql('ALTER TABLE sec_delegation_droit ADD CONSTRAINT FK_726DBC15FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE sec_delegation_droit ADD CONSTRAINT FK_726DBC15D852593C FOREIGN KEY (revoque_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE sec_jeton_reinitialisation ADD CONSTRAINT FK_C249465EFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE audit_entree ADD valeur_avant JSON DEFAULT NULL, ADD valeur_apres JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE sec_role ADD est_modele TINYINT DEFAULT 0 NOT NULL, ADD role_modele_origine_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE sec_role ADD CONSTRAINT FK_DC7D5AC0B3394980 FOREIGN KEY (role_modele_origine_id) REFERENCES sec_role (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_DC7D5AC0B3394980 ON sec_role (role_modele_origine_id)');

        // Cycle de vie utilisateur (RG-M8-01) : `statut` remplace le booléen `actif`. Ajout NULLABLE
        // d'abord, backfill depuis `actif` (rejouable sur une table non vide), puis NOT NULL + DROP.
        $this->addSql('ALTER TABLE sec_utilisateur ADD statut VARCHAR(12) DEFAULT NULL, ADD jeton_invitation VARCHAR(255) DEFAULT NULL, ADD jeton_invitation_expire DATETIME DEFAULT NULL, ADD dernier_acces DATETIME DEFAULT NULL, ADD mfa_actif TINYINT DEFAULT 0 NOT NULL, ADD mfa_secret VARCHAR(255) DEFAULT NULL, ADD mfa_codes_recuperation JSON DEFAULT NULL, ADD token_version INT DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE sec_utilisateur SET statut = CASE WHEN actif = 1 THEN 'actif' ELSE 'suspendu' END WHERE statut IS NULL");
        $this->addSql('ALTER TABLE sec_utilisateur MODIFY statut VARCHAR(12) NOT NULL');
        $this->addSql('ALTER TABLE sec_utilisateur DROP actif');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_43C1F3C4B2022B83 ON sec_utilisateur (jeton_invitation)');

        // Migration de données — nouvelles permissions `securite.lire`/`securite.exporter` (§5.2
        // plan). `INSERT IGNORE` (pas d'UUID_TO_BIN, fonction MySQL 8 absente de MariaDB 11.4,
        // même patron que Version20260814231500) : idempotent grâce à la contrainte unique
        // (module, action).
        $this->addSql(
            'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
            [Uuid::v4()->toBinary(), 'securite', 'lire'],
        );
        $this->addSql(
            'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
            [Uuid::v4()->toBinary(), 'securite', 'exporter'],
        );

        // Migration de données — rôles-modèles vides (§5.3 plan, cahier M8-02). Idempotent via
        // requête PHP (pas de contrainte unique exploitable côté SQL pour un simple IGNORE ici,
        // `sec_role.nom` porte déjà une contrainte unique -> INSERT IGNORE suffit aussi).
        foreach (['Caissier', 'Responsable de site', 'Contrôleur', 'Comptable'] as $nomRoleModele) {
            $this->addSql(
                'INSERT IGNORE INTO sec_role (id, nom, est_modele, role_modele_origine_id) VALUES (?, ?, 1, NULL)',
                [Uuid::v4()->toBinary(), $nomRoleModele],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_role WHERE nom IN ('Caissier', 'Responsable de site', 'Contrôleur', 'Comptable') AND est_modele = 1");
        $this->addSql("DELETE FROM sec_permission WHERE module = 'securite' AND action IN ('lire', 'exporter')");

        $this->addSql('ALTER TABLE sec_delegation_droit DROP FOREIGN KEY FK_726DBC1548FE0B5C');
        $this->addSql('ALTER TABLE sec_delegation_droit DROP FOREIGN KEY FK_726DBC155AF81F68');
        $this->addSql('ALTER TABLE sec_delegation_droit DROP FOREIGN KEY FK_726DBC15D60322AC');
        $this->addSql('ALTER TABLE sec_delegation_droit DROP FOREIGN KEY FK_726DBC15FF631228');
        $this->addSql('ALTER TABLE sec_delegation_droit DROP FOREIGN KEY FK_726DBC15D852593C');
        $this->addSql('ALTER TABLE sec_jeton_reinitialisation DROP FOREIGN KEY FK_C249465EFB88E14F');
        $this->addSql('DROP TABLE sec_delegation_droit');
        $this->addSql('DROP TABLE sec_jeton_reinitialisation');
        $this->addSql('ALTER TABLE audit_entree DROP valeur_avant, DROP valeur_apres');
        $this->addSql('ALTER TABLE sec_role DROP FOREIGN KEY FK_DC7D5AC0B3394980');
        $this->addSql('DROP INDEX IDX_DC7D5AC0B3394980 ON sec_role');
        $this->addSql('ALTER TABLE sec_role DROP est_modele, DROP role_modele_origine_id');
        $this->addSql('DROP INDEX UNIQ_43C1F3C4B2022B83 ON sec_utilisateur');
        $this->addSql("ALTER TABLE sec_utilisateur ADD actif TINYINT NOT NULL DEFAULT 1");
        $this->addSql("UPDATE sec_utilisateur SET actif = CASE WHEN statut = 'actif' THEN 1 ELSE 0 END");
        $this->addSql('ALTER TABLE sec_utilisateur DROP statut, DROP jeton_invitation, DROP jeton_invitation_expire, DROP dernier_acces, DROP mfa_actif, DROP mfa_secret, DROP mfa_codes_recuperation, DROP token_version');
    }
}
