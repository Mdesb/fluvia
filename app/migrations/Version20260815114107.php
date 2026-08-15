<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260815114107 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verticale Sport/Fitness : schéma sport_* (abonnement, échéancier SEPA, politique anti-impayés, '
            . 'incident/représentation, mandat SEPA, remise/rejet, statut d\'accès fitness, accès nocturne/SOS/'
            . 'présence isolée, file d\'attente comptable) + index/contraintes.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sport_abonnement_fitness (id BINARY(16) NOT NULL, periodicite VARCHAR(12) NOT NULL, statut VARCHAR(12) DEFAULT \'actif\' NOT NULL, date_souscription DATE NOT NULL, date_debut_engagement DATE NOT NULL, date_fin_engagement DATE NOT NULL, preavis_resiliation_jours SMALLINT NOT NULL, adherent_id BINARY(16) NOT NULL, payeur_id BINARY(16) NOT NULL, formule_id BINARY(16) NOT NULL, mandat_sepa_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_9D4AE73E25F06C53 (adherent_id), INDEX IDX_9D4AE73E422667C5 (payeur_id), INDEX IDX_9D4AE73E2A68F4D1 (formule_id), UNIQUE INDEX UNIQ_9D4AE73E610AFBEB (mandat_sepa_id), INDEX IDX_9D4AE73EFF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_alerte_presence_isolee (id BINARY(16) NOT NULL, horodatage DATETIME NOT NULL, nb_personnes_detectees SMALLINT NOT NULL, espace_acces_id BINARY(16) NOT NULL, INDEX IDX_2C838FD9F353E39C (espace_acces_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_config_acces_nocturne (id BINARY(16) NOT NULL, plage_debut VARCHAR(5) NOT NULL, plage_fin VARCHAR(5) NOT NULL, video_active TINYINT DEFAULT 1 NOT NULL, bouton_sos_actif TINYINT DEFAULT 1 NOT NULL, detection_presence_isolee_active TINYINT DEFAULT 1 NOT NULL, limite_occupation_nocturne INT DEFAULT NULL, espace_acces_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_config_nocturne_espace (espace_acces_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_echeance_sepa (id BINARY(16) NOT NULL, date_programmee DATE NOT NULL, montant_centimes INT NOT NULL, statut VARCHAR(10) DEFAULT \'a_venir\' NOT NULL, date_execution_reelle DATETIME DEFAULT NULL, abonnement_id BINARY(16) NOT NULL, remise_id BINARY(16) DEFAULT NULL, INDEX IDX_BD6EBE25F1D74413 (abonnement_id), INDEX IDX_BD6EBE254E47A399 (remise_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_evenement_sos (id BINARY(16) NOT NULL, horodatage DATETIME NOT NULL, statut VARCHAR(10) DEFAULT \'ouverte\' NOT NULL, date_traitement DATETIME DEFAULT NULL, espace_acces_id BINARY(16) NOT NULL, declenche_par_id BINARY(16) DEFAULT NULL, traite_par_id BINARY(16) DEFAULT NULL, INDEX IDX_BA68AFA8F353E39C (espace_acces_id), INDEX IDX_BA68AFA8C16F920B (declenche_par_id), INDEX IDX_BA68AFA8167FABE8 (traite_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_incident_prelevement (id BINARY(16) NOT NULL, montant_centimes INT NOT NULL, date_rejet DATE NOT NULL, motif_bancaire VARCHAR(4) NOT NULL, statut VARCHAR(14) DEFAULT \'representation\' NOT NULL, canal_resolution VARCHAR(10) DEFAULT NULL, date_resolution DATETIME DEFAULT NULL, motif_reouverture_forcee VARCHAR(255) DEFAULT NULL, abonnement_id BINARY(16) NOT NULL, echeance_origine_id BINARY(16) NOT NULL, rejet_origine_id BINARY(16) DEFAULT NULL, reouverture_forcee_par_id BINARY(16) DEFAULT NULL, INDEX IDX_408468E9F1D74413 (abonnement_id), INDEX IDX_408468E9E3AEB2EA (echeance_origine_id), INDEX IDX_408468E9A7F57829 (rejet_origine_id), INDEX IDX_408468E9D3401A5D (reouverture_forcee_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_mandat_sepa_fitness (id BINARY(16) NOT NULL, rum VARCHAR(35) NOT NULL, iban_token VARCHAR(128) NOT NULL, iban4_derniers VARCHAR(4) NOT NULL, titulaire VARCHAR(180) NOT NULL, date_signature DATE NOT NULL, statut VARCHAR(10) DEFAULT \'actif\' NOT NULL, abonnement_rattache_id BINARY(16) DEFAULT NULL, payeur_id BINARY(16) NOT NULL, INDEX IDX_72DCE8B6422667C5 (payeur_id), UNIQUE INDEX uniq_mandat_rum (rum), UNIQUE INDEX uniq_mandat_abonnement (abonnement_rattache_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_mouvement_comptable_sepa (id BINARY(16) NOT NULL, type VARCHAR(12) NOT NULL, montant_centimes INT NOT NULL, date_fait_generateur DATE NOT NULL, origine VARCHAR(20) NOT NULL, statut_transmission VARCHAR(12) DEFAULT \'en_attente\' NOT NULL, transmis_le DATETIME DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, abonnement_id BINARY(16) NOT NULL, INDEX IDX_800E99E8FF631228 (etablissement_id), INDEX IDX_800E99E8F1D74413 (abonnement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_pause_abonnement (id BINARY(16) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, motif VARCHAR(255) DEFAULT NULL, statut VARCHAR(10) DEFAULT \'active\' NOT NULL, abonnement_id BINARY(16) NOT NULL, INDEX IDX_8370E48EF1D74413 (abonnement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_politique_anti_impayes (id BINARY(16) NOT NULL, nb_representations_max SMALLINT DEFAULT 1 NOT NULL, calendrier_representation_jours JSON NOT NULL, moment_refus_badge VARCHAR(32) DEFAULT \'apres_representation_echouee\' NOT NULL, n_repr_avant_badge SMALLINT DEFAULT NULL, delai_avant_suspension_contrat_jours SMALLINT DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_politique_etablissement (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_reengagement (id BINARY(16) NOT NULL, date_reengagement DATE NOT NULL, ancien_abonnement_id BINARY(16) NOT NULL, nouvel_abonnement_id BINARY(16) NOT NULL, nouveau_mandat_id BINARY(16) NOT NULL, INDEX IDX_361E5BA8C6306A60 (ancien_abonnement_id), INDEX IDX_361E5BA82C3C8B24 (nouveau_mandat_id), UNIQUE INDEX uniq_reengagement_nouvel_abonnement (nouvel_abonnement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_rejet_prelevement (id BINARY(16) NOT NULL, code_retour VARCHAR(4) NOT NULL, libelle_retour VARCHAR(255) DEFAULT NULL, date_reception DATETIME NOT NULL, montant_centimes INT NOT NULL, statut VARCHAR(10) DEFAULT \'nouveau\' NOT NULL, remise_id BINARY(16) DEFAULT NULL, echeance_id BINARY(16) NOT NULL, INDEX IDX_39CB49A74E47A399 (remise_id), INDEX IDX_39CB49A75B318673 (echeance_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_remise_sepa (id BINARY(16) NOT NULL, reference_remise VARCHAR(35) DEFAULT NULL, date_generation DATETIME NOT NULL, date_execution_prevue DATE NOT NULL, statut VARCHAR(10) DEFAULT \'brouillon\' NOT NULL, nb_echeances INT DEFAULT 0 NOT NULL, montant_total_centimes INT DEFAULT 0 NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_E65C2E0BFF631228 (etablissement_id), UNIQUE INDEX uniq_remise_reference (reference_remise), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_representation_sepa (id BINARY(16) NOT NULL, date_programmee DATE NOT NULL, date_execution DATETIME DEFAULT NULL, resultat VARCHAR(10) DEFAULT \'en_attente\' NOT NULL, incident_id BINARY(16) NOT NULL, INDEX IDX_CCC066F359E53FB9 (incident_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_resiliation (id BINARY(16) NOT NULL, date_demande DATE NOT NULL, motif VARCHAR(255) NOT NULL, motif_legitime TINYINT DEFAULT 0 NOT NULL, justificatif_chemin VARCHAR(255) DEFAULT NULL, preavis_applique_jours SMALLINT NOT NULL, date_effet DATE NOT NULL, statut VARCHAR(12) DEFAULT \'refusee\' NOT NULL, abonnement_id BINARY(16) NOT NULL, valide_par_utilisateur_id BINARY(16) DEFAULT NULL, INDEX IDX_B3334F81F1D74413 (abonnement_id), INDEX IDX_B3334F819E1BD977 (valide_par_utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sport_statut_acces_fitness (id BINARY(16) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, motif_inactivite VARCHAR(12) DEFAULT NULL, date_derniere_propagation DATETIME DEFAULT NULL, abonnement_id BINARY(16) NOT NULL, droit_acces_id BINARY(16) DEFAULT NULL, INDEX IDX_699427C82453207F (droit_acces_id), UNIQUE INDEX uniq_statut_acces_abonnement (abonnement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sport_abonnement_fitness ADD CONSTRAINT FK_9D4AE73E25F06C53 FOREIGN KEY (adherent_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE sport_abonnement_fitness ADD CONSTRAINT FK_9D4AE73E422667C5 FOREIGN KEY (payeur_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE sport_abonnement_fitness ADD CONSTRAINT FK_9D4AE73E2A68F4D1 FOREIGN KEY (formule_id) REFERENCES off_formule (id)');
        $this->addSql('ALTER TABLE sport_abonnement_fitness ADD CONSTRAINT FK_9D4AE73E610AFBEB FOREIGN KEY (mandat_sepa_id) REFERENCES sport_mandat_sepa_fitness (id)');
        $this->addSql('ALTER TABLE sport_abonnement_fitness ADD CONSTRAINT FK_9D4AE73EFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE sport_alerte_presence_isolee ADD CONSTRAINT FK_2C838FD9F353E39C FOREIGN KEY (espace_acces_id) REFERENCES acces_espace_acces (id)');
        $this->addSql('ALTER TABLE sport_config_acces_nocturne ADD CONSTRAINT FK_EC139E60F353E39C FOREIGN KEY (espace_acces_id) REFERENCES acces_espace_acces (id)');
        $this->addSql('ALTER TABLE sport_echeance_sepa ADD CONSTRAINT FK_BD6EBE25F1D74413 FOREIGN KEY (abonnement_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE sport_echeance_sepa ADD CONSTRAINT FK_BD6EBE254E47A399 FOREIGN KEY (remise_id) REFERENCES sport_remise_sepa (id)');
        $this->addSql('ALTER TABLE sport_evenement_sos ADD CONSTRAINT FK_BA68AFA8F353E39C FOREIGN KEY (espace_acces_id) REFERENCES acces_espace_acces (id)');
        $this->addSql('ALTER TABLE sport_evenement_sos ADD CONSTRAINT FK_BA68AFA8C16F920B FOREIGN KEY (declenche_par_id) REFERENCES acces_support (id)');
        $this->addSql('ALTER TABLE sport_evenement_sos ADD CONSTRAINT FK_BA68AFA8167FABE8 FOREIGN KEY (traite_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE sport_incident_prelevement ADD CONSTRAINT FK_408468E9F1D74413 FOREIGN KEY (abonnement_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE sport_incident_prelevement ADD CONSTRAINT FK_408468E9E3AEB2EA FOREIGN KEY (echeance_origine_id) REFERENCES sport_echeance_sepa (id)');
        $this->addSql('ALTER TABLE sport_incident_prelevement ADD CONSTRAINT FK_408468E9A7F57829 FOREIGN KEY (rejet_origine_id) REFERENCES sport_rejet_prelevement (id)');
        $this->addSql('ALTER TABLE sport_incident_prelevement ADD CONSTRAINT FK_408468E9D3401A5D FOREIGN KEY (reouverture_forcee_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE sport_mandat_sepa_fitness ADD CONSTRAINT FK_72DCE8B61F89C938 FOREIGN KEY (abonnement_rattache_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE sport_mandat_sepa_fitness ADD CONSTRAINT FK_72DCE8B6422667C5 FOREIGN KEY (payeur_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE sport_mouvement_comptable_sepa ADD CONSTRAINT FK_800E99E8FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE sport_mouvement_comptable_sepa ADD CONSTRAINT FK_800E99E8F1D74413 FOREIGN KEY (abonnement_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE sport_pause_abonnement ADD CONSTRAINT FK_8370E48EF1D74413 FOREIGN KEY (abonnement_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE sport_politique_anti_impayes ADD CONSTRAINT FK_6CF903BAFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE sport_reengagement ADD CONSTRAINT FK_361E5BA8C6306A60 FOREIGN KEY (ancien_abonnement_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE sport_reengagement ADD CONSTRAINT FK_361E5BA834C761BD FOREIGN KEY (nouvel_abonnement_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE sport_reengagement ADD CONSTRAINT FK_361E5BA82C3C8B24 FOREIGN KEY (nouveau_mandat_id) REFERENCES sport_mandat_sepa_fitness (id)');
        $this->addSql('ALTER TABLE sport_rejet_prelevement ADD CONSTRAINT FK_39CB49A74E47A399 FOREIGN KEY (remise_id) REFERENCES sport_remise_sepa (id)');
        $this->addSql('ALTER TABLE sport_rejet_prelevement ADD CONSTRAINT FK_39CB49A75B318673 FOREIGN KEY (echeance_id) REFERENCES sport_echeance_sepa (id)');
        $this->addSql('ALTER TABLE sport_remise_sepa ADD CONSTRAINT FK_E65C2E0BFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE sport_representation_sepa ADD CONSTRAINT FK_CCC066F359E53FB9 FOREIGN KEY (incident_id) REFERENCES sport_incident_prelevement (id)');
        $this->addSql('ALTER TABLE sport_resiliation ADD CONSTRAINT FK_B3334F81F1D74413 FOREIGN KEY (abonnement_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE sport_resiliation ADD CONSTRAINT FK_B3334F819E1BD977 FOREIGN KEY (valide_par_utilisateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE sport_statut_acces_fitness ADD CONSTRAINT FK_699427C8F1D74413 FOREIGN KEY (abonnement_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE sport_statut_acces_fitness ADD CONSTRAINT FK_699427C82453207F FOREIGN KEY (droit_acces_id) REFERENCES acces_droit_acces (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sport_abonnement_fitness DROP FOREIGN KEY FK_9D4AE73E25F06C53');
        $this->addSql('ALTER TABLE sport_abonnement_fitness DROP FOREIGN KEY FK_9D4AE73E422667C5');
        $this->addSql('ALTER TABLE sport_abonnement_fitness DROP FOREIGN KEY FK_9D4AE73E2A68F4D1');
        $this->addSql('ALTER TABLE sport_abonnement_fitness DROP FOREIGN KEY FK_9D4AE73E610AFBEB');
        $this->addSql('ALTER TABLE sport_abonnement_fitness DROP FOREIGN KEY FK_9D4AE73EFF631228');
        $this->addSql('ALTER TABLE sport_alerte_presence_isolee DROP FOREIGN KEY FK_2C838FD9F353E39C');
        $this->addSql('ALTER TABLE sport_config_acces_nocturne DROP FOREIGN KEY FK_EC139E60F353E39C');
        $this->addSql('ALTER TABLE sport_echeance_sepa DROP FOREIGN KEY FK_BD6EBE25F1D74413');
        $this->addSql('ALTER TABLE sport_echeance_sepa DROP FOREIGN KEY FK_BD6EBE254E47A399');
        $this->addSql('ALTER TABLE sport_evenement_sos DROP FOREIGN KEY FK_BA68AFA8F353E39C');
        $this->addSql('ALTER TABLE sport_evenement_sos DROP FOREIGN KEY FK_BA68AFA8C16F920B');
        $this->addSql('ALTER TABLE sport_evenement_sos DROP FOREIGN KEY FK_BA68AFA8167FABE8');
        $this->addSql('ALTER TABLE sport_incident_prelevement DROP FOREIGN KEY FK_408468E9F1D74413');
        $this->addSql('ALTER TABLE sport_incident_prelevement DROP FOREIGN KEY FK_408468E9E3AEB2EA');
        $this->addSql('ALTER TABLE sport_incident_prelevement DROP FOREIGN KEY FK_408468E9A7F57829');
        $this->addSql('ALTER TABLE sport_incident_prelevement DROP FOREIGN KEY FK_408468E9D3401A5D');
        $this->addSql('ALTER TABLE sport_mandat_sepa_fitness DROP FOREIGN KEY FK_72DCE8B61F89C938');
        $this->addSql('ALTER TABLE sport_mandat_sepa_fitness DROP FOREIGN KEY FK_72DCE8B6422667C5');
        $this->addSql('ALTER TABLE sport_mouvement_comptable_sepa DROP FOREIGN KEY FK_800E99E8FF631228');
        $this->addSql('ALTER TABLE sport_mouvement_comptable_sepa DROP FOREIGN KEY FK_800E99E8F1D74413');
        $this->addSql('ALTER TABLE sport_pause_abonnement DROP FOREIGN KEY FK_8370E48EF1D74413');
        $this->addSql('ALTER TABLE sport_politique_anti_impayes DROP FOREIGN KEY FK_6CF903BAFF631228');
        $this->addSql('ALTER TABLE sport_reengagement DROP FOREIGN KEY FK_361E5BA8C6306A60');
        $this->addSql('ALTER TABLE sport_reengagement DROP FOREIGN KEY FK_361E5BA834C761BD');
        $this->addSql('ALTER TABLE sport_reengagement DROP FOREIGN KEY FK_361E5BA82C3C8B24');
        $this->addSql('ALTER TABLE sport_rejet_prelevement DROP FOREIGN KEY FK_39CB49A74E47A399');
        $this->addSql('ALTER TABLE sport_rejet_prelevement DROP FOREIGN KEY FK_39CB49A75B318673');
        $this->addSql('ALTER TABLE sport_remise_sepa DROP FOREIGN KEY FK_E65C2E0BFF631228');
        $this->addSql('ALTER TABLE sport_representation_sepa DROP FOREIGN KEY FK_CCC066F359E53FB9');
        $this->addSql('ALTER TABLE sport_resiliation DROP FOREIGN KEY FK_B3334F81F1D74413');
        $this->addSql('ALTER TABLE sport_resiliation DROP FOREIGN KEY FK_B3334F819E1BD977');
        $this->addSql('ALTER TABLE sport_statut_acces_fitness DROP FOREIGN KEY FK_699427C8F1D74413');
        $this->addSql('ALTER TABLE sport_statut_acces_fitness DROP FOREIGN KEY FK_699427C82453207F');
        $this->addSql('DROP TABLE sport_abonnement_fitness');
        $this->addSql('DROP TABLE sport_alerte_presence_isolee');
        $this->addSql('DROP TABLE sport_config_acces_nocturne');
        $this->addSql('DROP TABLE sport_echeance_sepa');
        $this->addSql('DROP TABLE sport_evenement_sos');
        $this->addSql('DROP TABLE sport_incident_prelevement');
        $this->addSql('DROP TABLE sport_mandat_sepa_fitness');
        $this->addSql('DROP TABLE sport_mouvement_comptable_sepa');
        $this->addSql('DROP TABLE sport_pause_abonnement');
        $this->addSql('DROP TABLE sport_politique_anti_impayes');
        $this->addSql('DROP TABLE sport_reengagement');
        $this->addSql('DROP TABLE sport_rejet_prelevement');
        $this->addSql('DROP TABLE sport_remise_sepa');
        $this->addSql('DROP TABLE sport_representation_sepa');
        $this->addSql('DROP TABLE sport_resiliation');
        $this->addSql('DROP TABLE sport_statut_acces_fitness');
    }
}
