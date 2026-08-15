<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extraction du moteur anti-impayés de `App\Sport` vers le module partagé `App\Recouvrement`
 * (refactor, réutilisable par toute activité à abonnement : Sport, Piscine en régie, futures). Schéma
 * `recouvrement_*` (politique, incident impayé, représentation SEPA) en remplacement de
 * `sport_politique_anti_impayes`/`sport_incident_prelevement`/`sport_representation_sepa`/
 * `sport_rejet_prelevement`. Environnement de dev/démo (aucune donnée de production à ce stade, même
 * convention que `Version20260815153119`) : migration de type « déplacement de schéma », pas de
 * réinjection ligne à ligne — les fixtures rechargent l'état de démonstration. `IncidentImpaye`
 * référence désormais `App\Sepa\Entity\RejetSepa` (convergence, nullable) au lieu de porter son propre
 * `RejetPrelevement` ; le contrat en cause (ex. `AbonnementFitness`) n'est plus une association
 * Doctrine mais un couple opaque `type_redevable`/`reference_redevable` (aucune dépendance de
 * `App\Recouvrement` vers une verticale).
 */
final class Version20260815184354 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extraction du moteur anti-impayés App\\Sport → module partagé App\\Recouvrement : schéma recouvrement_* '
            . '(politique, incident impayé, représentation) en remplacement de sport_politique_anti_impayes/'
            . 'sport_incident_prelevement/sport_representation_sepa/sport_rejet_prelevement.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE recouvrement_incident_impaye (id BINARY(16) NOT NULL, type_redevable VARCHAR(60) NOT NULL, reference_redevable VARCHAR(64) NOT NULL, reference_echeance_origine VARCHAR(64) DEFAULT NULL, montant_centimes INT NOT NULL, date_rejet DATE NOT NULL, motif_bancaire VARCHAR(4) NOT NULL, libelle_motif_bancaire VARCHAR(255) DEFAULT NULL, statut VARCHAR(14) DEFAULT \'representation\' NOT NULL, canal_resolution VARCHAR(10) DEFAULT NULL, date_resolution DATETIME DEFAULT NULL, motif_reouverture_forcee VARCHAR(255) DEFAULT NULL, acces_bloque TINYINT DEFAULT 0 NOT NULL, etablissement_id BINARY(16) NOT NULL, rejet_origine_id BINARY(16) DEFAULT NULL, reouverture_forcee_par_id BINARY(16) DEFAULT NULL, INDEX IDX_A6BD1F5FF631228 (etablissement_id), INDEX IDX_A6BD1F5A7F57829 (rejet_origine_id), INDEX IDX_A6BD1F5D3401A5D (reouverture_forcee_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE recouvrement_politique (id BINARY(16) NOT NULL, nb_representations_max SMALLINT DEFAULT 1 NOT NULL, calendrier_representation_jours JSON NOT NULL, moment_refus_acces VARCHAR(32) DEFAULT \'apres_representation_echouee\' NOT NULL, n_repr_avant_blocage SMALLINT DEFAULT NULL, delai_avant_suspension_contrat_jours SMALLINT DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_recouvrement_politique_etablissement (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE recouvrement_representation (id BINARY(16) NOT NULL, date_programmee DATE NOT NULL, date_execution DATETIME DEFAULT NULL, resultat VARCHAR(10) DEFAULT \'en_attente\' NOT NULL, incident_id BINARY(16) NOT NULL, INDEX IDX_8C767FE859E53FB9 (incident_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE recouvrement_incident_impaye ADD CONSTRAINT FK_A6BD1F5FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE recouvrement_incident_impaye ADD CONSTRAINT FK_A6BD1F5A7F57829 FOREIGN KEY (rejet_origine_id) REFERENCES sepa_rejet (id)');
        $this->addSql('ALTER TABLE recouvrement_incident_impaye ADD CONSTRAINT FK_A6BD1F5D3401A5D FOREIGN KEY (reouverture_forcee_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE recouvrement_politique ADD CONSTRAINT FK_E9A00413FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE recouvrement_representation ADD CONSTRAINT FK_8C767FE859E53FB9 FOREIGN KEY (incident_id) REFERENCES recouvrement_incident_impaye (id)');
        $this->addSql('ALTER TABLE sport_incident_prelevement DROP FOREIGN KEY `FK_408468E9A7F57829`');
        $this->addSql('ALTER TABLE sport_incident_prelevement DROP FOREIGN KEY `FK_408468E9D3401A5D`');
        $this->addSql('ALTER TABLE sport_incident_prelevement DROP FOREIGN KEY `FK_408468E9E3AEB2EA`');
        $this->addSql('ALTER TABLE sport_incident_prelevement DROP FOREIGN KEY `FK_408468E9F1D74413`');
        $this->addSql('ALTER TABLE sport_politique_anti_impayes DROP FOREIGN KEY `FK_6CF903BAFF631228`');
        $this->addSql('ALTER TABLE sport_rejet_prelevement DROP FOREIGN KEY `FK_39CB49A74E47A399`');
        $this->addSql('ALTER TABLE sport_rejet_prelevement DROP FOREIGN KEY `FK_39CB49A75B318673`');
        $this->addSql('ALTER TABLE sport_representation_sepa DROP FOREIGN KEY `FK_CCC066F359E53FB9`');
        $this->addSql('DROP TABLE sport_incident_prelevement');
        $this->addSql('DROP TABLE sport_politique_anti_impayes');
        $this->addSql('DROP TABLE sport_rejet_prelevement');
        $this->addSql('DROP TABLE sport_representation_sepa');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sport_incident_prelevement (id BINARY(16) NOT NULL, montant_centimes INT NOT NULL, date_rejet DATE NOT NULL, motif_bancaire VARCHAR(4) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, statut VARCHAR(14) CHARACTER SET utf8mb4 DEFAULT \'representation\' NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, canal_resolution VARCHAR(10) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, date_resolution DATETIME DEFAULT NULL, motif_reouverture_forcee VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, abonnement_id BINARY(16) NOT NULL, echeance_origine_id BINARY(16) NOT NULL, rejet_origine_id BINARY(16) DEFAULT NULL, reouverture_forcee_par_id BINARY(16) DEFAULT NULL, INDEX IDX_408468E9A7F57829 (rejet_origine_id), INDEX IDX_408468E9D3401A5D (reouverture_forcee_par_id), INDEX IDX_408468E9F1D74413 (abonnement_id), INDEX IDX_408468E9E3AEB2EA (echeance_origine_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sport_politique_anti_impayes (id BINARY(16) NOT NULL, nb_representations_max SMALLINT DEFAULT 1 NOT NULL, calendrier_representation_jours JSON NOT NULL, moment_refus_badge VARCHAR(32) CHARACTER SET utf8mb4 DEFAULT \'apres_representation_echouee\' NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, n_repr_avant_badge SMALLINT DEFAULT NULL, delai_avant_suspension_contrat_jours SMALLINT DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_politique_etablissement (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sport_rejet_prelevement (id BINARY(16) NOT NULL, code_retour VARCHAR(4) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, libelle_retour VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, date_reception DATETIME NOT NULL, montant_centimes INT NOT NULL, statut VARCHAR(10) CHARACTER SET utf8mb4 DEFAULT \'nouveau\' NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, remise_id BINARY(16) DEFAULT NULL, echeance_id BINARY(16) NOT NULL, INDEX IDX_39CB49A74E47A399 (remise_id), INDEX IDX_39CB49A75B318673 (echeance_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sport_representation_sepa (id BINARY(16) NOT NULL, date_programmee DATE NOT NULL, date_execution DATETIME DEFAULT NULL, resultat VARCHAR(10) CHARACTER SET utf8mb4 DEFAULT \'en_attente\' NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, incident_id BINARY(16) NOT NULL, INDEX IDX_CCC066F359E53FB9 (incident_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE sport_incident_prelevement ADD CONSTRAINT `FK_408468E9A7F57829` FOREIGN KEY (rejet_origine_id) REFERENCES sport_rejet_prelevement (id)');
        $this->addSql('ALTER TABLE sport_incident_prelevement ADD CONSTRAINT `FK_408468E9D3401A5D` FOREIGN KEY (reouverture_forcee_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE sport_incident_prelevement ADD CONSTRAINT `FK_408468E9E3AEB2EA` FOREIGN KEY (echeance_origine_id) REFERENCES sport_echeance_sepa (id)');
        $this->addSql('ALTER TABLE sport_incident_prelevement ADD CONSTRAINT `FK_408468E9F1D74413` FOREIGN KEY (abonnement_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE sport_politique_anti_impayes ADD CONSTRAINT `FK_6CF903BAFF631228` FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE sport_rejet_prelevement ADD CONSTRAINT `FK_39CB49A74E47A399` FOREIGN KEY (remise_id) REFERENCES sepa_remise (id)');
        $this->addSql('ALTER TABLE sport_rejet_prelevement ADD CONSTRAINT `FK_39CB49A75B318673` FOREIGN KEY (echeance_id) REFERENCES sport_echeance_sepa (id)');
        $this->addSql('ALTER TABLE sport_representation_sepa ADD CONSTRAINT `FK_CCC066F359E53FB9` FOREIGN KEY (incident_id) REFERENCES sport_incident_prelevement (id)');
        $this->addSql('ALTER TABLE recouvrement_incident_impaye DROP FOREIGN KEY FK_A6BD1F5FF631228');
        $this->addSql('ALTER TABLE recouvrement_incident_impaye DROP FOREIGN KEY FK_A6BD1F5A7F57829');
        $this->addSql('ALTER TABLE recouvrement_incident_impaye DROP FOREIGN KEY FK_A6BD1F5D3401A5D');
        $this->addSql('ALTER TABLE recouvrement_politique DROP FOREIGN KEY FK_E9A00413FF631228');
        $this->addSql('ALTER TABLE recouvrement_representation DROP FOREIGN KEY FK_8C767FE859E53FB9');
        $this->addSql('DROP TABLE recouvrement_incident_impaye');
        $this->addSql('DROP TABLE recouvrement_politique');
        $this->addSql('DROP TABLE recouvrement_representation');
    }
}
