<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module SEPA partagé App\Sepa (plan-sepa.md §7) : schéma `sepa_*` (config créancier, mandat, remise,
 * ligne de remise, rejet) + déplacement des données mandat/remise de la verticale Sport
 * (`sport_mandat_sepa_fitness` → `sepa_mandat`, `sport_remise_sepa` → `sepa_remise`, ex-données de
 * fixtures uniquement — environnement de dev/démo, aucune donnée de production à ce stade) et
 * repointage des FK dépendantes (`sport_abonnement_fitness`, `sport_echeance_sepa`,
 * `sport_reengagement`, `sport_rejet_prelevement`).
 */
final class Version20260815153119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module SEPA partagé App\Sepa : schéma sepa_* (config créancier, mandat, remise, ligne, rejet) '
            . '+ déplacement sport_mandat_sepa_fitness/sport_remise_sepa → sepa_mandat/sepa_remise.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sepa_config_creancier (id BINARY(16) NOT NULL, variante VARCHAR(8) DEFAULT NULL, ics VARCHAR(35) NOT NULL, creancier_nom VARCHAR(140) NOT NULL, creancier_iban_token VARCHAR(128) DEFAULT \'\' NOT NULL, creancier_iban4_derniers VARCHAR(4) DEFAULT \'\' NOT NULL, creancier_bic VARCHAR(11) NOT NULL, collectivite_nom VARCHAR(140) DEFAULT NULL, ultimate_creancier_nom VARCHAR(140) DEFAULT NULL, ultimate_creancier_org_id VARCHAR(35) DEFAULT NULL, modifie_le DATETIME NOT NULL, etablissement_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_config_creancier_etablissement (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sepa_ligne_remise (id BINARY(16) NOT NULL, seq_tp VARCHAR(4) NOT NULL, montant_centimes INT NOT NULL, end_to_end_id VARCHAR(35) NOT NULL, libelle VARCHAR(140) NOT NULL, reference_origine VARCHAR(64) DEFAULT NULL, remise_id BINARY(16) NOT NULL, mandat_id BINARY(16) NOT NULL, INDEX IDX_C8D8AD824E47A399 (remise_id), INDEX IDX_C8D8AD82C687DD98 (mandat_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sepa_mandat (id BINARY(16) NOT NULL, rum VARCHAR(35) NOT NULL, iban_token VARCHAR(128) NOT NULL, iban4_derniers VARCHAR(4) NOT NULL, bic_debiteur VARCHAR(11) NOT NULL, debiteur_nom VARCHAR(180) NOT NULL, date_signature DATE NOT NULL, statut VARCHAR(10) DEFAULT \'actif\' NOT NULL, sequence_courante VARCHAR(4) DEFAULT NULL, nb_collectes_reussies INT DEFAULT 0 NOT NULL, client_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_AD67FEF219EB6921 (client_id), INDEX IDX_AD67FEF2FF631228 (etablissement_id), UNIQUE INDEX uniq_sepa_mandat_rum (rum), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sepa_rejet (id BINARY(16) NOT NULL, end_to_end_id VARCHAR(35) NOT NULL, mndt_id VARCHAR(35) NOT NULL, code_motif VARCHAR(4) NOT NULL, libelle_motif VARCHAR(255) DEFAULT NULL, date_rejet DATE NOT NULL, date_saisie DATETIME NOT NULL, ligne_id BINARY(16) NOT NULL, INDEX IDX_11012E4A5A438E76 (ligne_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sepa_remise (id BINARY(16) NOT NULL, message_id VARCHAR(35) DEFAULT NULL, date_creation DATETIME NOT NULL, date_collecte DATE NOT NULL, seq_tp VARCHAR(4) DEFAULT NULL, nb_txs INT DEFAULT 0 NOT NULL, ctrl_sum_centimes INT DEFAULT 0 NOT NULL, statut VARCHAR(10) DEFAULT \'brouillon\' NOT NULL, contenu_xml LONGTEXT DEFAULT NULL, reference_transmission VARCHAR(64) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_A24E84E0FF631228 (etablissement_id), UNIQUE INDEX uniq_sepa_remise_message_id (message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sepa_config_creancier ADD CONSTRAINT FK_FE541F1FFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE sepa_ligne_remise ADD CONSTRAINT FK_C8D8AD824E47A399 FOREIGN KEY (remise_id) REFERENCES sepa_remise (id)');
        $this->addSql('ALTER TABLE sepa_ligne_remise ADD CONSTRAINT FK_C8D8AD82C687DD98 FOREIGN KEY (mandat_id) REFERENCES sepa_mandat (id)');
        $this->addSql('ALTER TABLE sepa_mandat ADD CONSTRAINT FK_AD67FEF219EB6921 FOREIGN KEY (client_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE sepa_mandat ADD CONSTRAINT FK_AD67FEF2FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE sepa_rejet ADD CONSTRAINT FK_11012E4A5A438E76 FOREIGN KEY (ligne_id) REFERENCES sepa_ligne_remise (id)');
        $this->addSql('ALTER TABLE sepa_remise ADD CONSTRAINT FK_A24E84E0FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        // Réordonnancement manuel par rapport au diff auto-généré (§7 du plan) : toutes les FK
        // dépendantes de sport_mandat_sepa_fitness/sport_remise_sepa (tables enfants ET tables qui les
        // référencent) doivent être coupées AVANT le DROP TABLE, sous peine de violation de contrainte
        // (MariaDB 1451). Environnement de dev/démo : aucune donnée de production à préserver à ce
        // stade — migration de type « déplacement de schéma », pas de réinjection ligne à ligne.
        $this->addSql('ALTER TABLE sport_mandat_sepa_fitness DROP FOREIGN KEY `FK_72DCE8B61F89C938`');
        $this->addSql('ALTER TABLE sport_mandat_sepa_fitness DROP FOREIGN KEY `FK_72DCE8B6422667C5`');
        $this->addSql('ALTER TABLE sport_abonnement_fitness DROP FOREIGN KEY `FK_9D4AE73E610AFBEB`');
        $this->addSql('ALTER TABLE sport_echeance_sepa DROP FOREIGN KEY `FK_BD6EBE254E47A399`');
        $this->addSql('ALTER TABLE sport_reengagement DROP FOREIGN KEY `FK_361E5BA82C3C8B24`');
        $this->addSql('ALTER TABLE sport_rejet_prelevement DROP FOREIGN KEY `FK_39CB49A74E47A399`');
        $this->addSql('DROP TABLE sport_mandat_sepa_fitness');
        $this->addSql('DROP TABLE sport_remise_sepa');
        $this->addSql('ALTER TABLE sport_abonnement_fitness ADD CONSTRAINT FK_9D4AE73E610AFBEB FOREIGN KEY (mandat_sepa_id) REFERENCES sepa_mandat (id)');
        $this->addSql('ALTER TABLE sport_echeance_sepa ADD CONSTRAINT FK_BD6EBE254E47A399 FOREIGN KEY (remise_id) REFERENCES sepa_remise (id)');
        $this->addSql('ALTER TABLE sport_reengagement ADD CONSTRAINT FK_361E5BA82C3C8B24 FOREIGN KEY (nouveau_mandat_id) REFERENCES sepa_mandat (id)');
        $this->addSql('ALTER TABLE sport_rejet_prelevement ADD CONSTRAINT FK_39CB49A74E47A399 FOREIGN KEY (remise_id) REFERENCES sepa_remise (id)');
    }

    public function down(Schema $schema): void
    {
        // Réordonnancement manuel (même raison que up()) : recréer les anciennes tables, couper toutes
        // les FK qui pointent vers sepa_mandat/sepa_remise AVANT de les DROP, puis seulement rebrancher.
        $this->addSql('CREATE TABLE sport_mandat_sepa_fitness (id BINARY(16) NOT NULL, rum VARCHAR(35) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, iban_token VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, iban4_derniers VARCHAR(4) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, titulaire VARCHAR(180) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, date_signature DATE NOT NULL, statut VARCHAR(10) CHARACTER SET utf8mb4 DEFAULT \'actif\' NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, abonnement_rattache_id BINARY(16) DEFAULT NULL, payeur_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_mandat_rum (rum), UNIQUE INDEX uniq_mandat_abonnement (abonnement_rattache_id), INDEX IDX_72DCE8B6422667C5 (payeur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sport_remise_sepa (id BINARY(16) NOT NULL, reference_remise VARCHAR(35) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, date_generation DATETIME NOT NULL, date_execution_prevue DATE NOT NULL, statut VARCHAR(10) CHARACTER SET utf8mb4 DEFAULT \'brouillon\' NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, nb_echeances INT DEFAULT 0 NOT NULL, montant_total_centimes INT DEFAULT 0 NOT NULL, etablissement_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_remise_reference (reference_remise), INDEX IDX_E65C2E0BFF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE sport_abonnement_fitness DROP FOREIGN KEY FK_9D4AE73E610AFBEB');
        $this->addSql('ALTER TABLE sport_echeance_sepa DROP FOREIGN KEY FK_BD6EBE254E47A399');
        $this->addSql('ALTER TABLE sport_reengagement DROP FOREIGN KEY FK_361E5BA82C3C8B24');
        $this->addSql('ALTER TABLE sport_rejet_prelevement DROP FOREIGN KEY FK_39CB49A74E47A399');
        $this->addSql('ALTER TABLE sepa_config_creancier DROP FOREIGN KEY FK_FE541F1FFF631228');
        $this->addSql('ALTER TABLE sepa_ligne_remise DROP FOREIGN KEY FK_C8D8AD824E47A399');
        $this->addSql('ALTER TABLE sepa_ligne_remise DROP FOREIGN KEY FK_C8D8AD82C687DD98');
        $this->addSql('ALTER TABLE sepa_mandat DROP FOREIGN KEY FK_AD67FEF219EB6921');
        $this->addSql('ALTER TABLE sepa_mandat DROP FOREIGN KEY FK_AD67FEF2FF631228');
        $this->addSql('ALTER TABLE sepa_rejet DROP FOREIGN KEY FK_11012E4A5A438E76');
        $this->addSql('ALTER TABLE sepa_remise DROP FOREIGN KEY FK_A24E84E0FF631228');
        $this->addSql('DROP TABLE sepa_config_creancier');
        $this->addSql('DROP TABLE sepa_ligne_remise');
        $this->addSql('DROP TABLE sepa_mandat');
        $this->addSql('DROP TABLE sepa_rejet');
        $this->addSql('DROP TABLE sepa_remise');
        $this->addSql('ALTER TABLE sport_mandat_sepa_fitness ADD CONSTRAINT `FK_72DCE8B61F89C938` FOREIGN KEY (abonnement_rattache_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE sport_mandat_sepa_fitness ADD CONSTRAINT `FK_72DCE8B6422667C5` FOREIGN KEY (payeur_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE sport_remise_sepa ADD CONSTRAINT `FK_E65C2E0BFF631228` FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE sport_abonnement_fitness ADD CONSTRAINT `FK_9D4AE73E610AFBEB` FOREIGN KEY (mandat_sepa_id) REFERENCES sport_mandat_sepa_fitness (id)');
        $this->addSql('ALTER TABLE sport_echeance_sepa ADD CONSTRAINT `FK_BD6EBE254E47A399` FOREIGN KEY (remise_id) REFERENCES sport_remise_sepa (id)');
        $this->addSql('ALTER TABLE sport_reengagement ADD CONSTRAINT `FK_361E5BA82C3C8B24` FOREIGN KEY (nouveau_mandat_id) REFERENCES sport_mandat_sepa_fitness (id)');
        $this->addSql('ALTER TABLE sport_rejet_prelevement ADD CONSTRAINT `FK_39CB49A74E47A399` FOREIGN KEY (remise_id) REFERENCES sport_remise_sepa (id)');
    }
}
