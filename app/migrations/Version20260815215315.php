<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260815215315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module socle M5 Réservation & no-show (App\\Reservation) : Ressource, Disponibilite/Indisponibilite, Activite, Recurrence, Creneau, Reservation, ParticipantReservation, ListeAttente, RegleAnnulation, Emargement, FacturationNoShow, ProjectionAccesReservation.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE reservation_activite (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, type_activite VARCHAR(60) NOT NULL, duree_minutes SMALLINT NOT NULL, niveau_requis VARCHAR(60) DEFAULT NULL, competence_exigee VARCHAR(80) DEFAULT NULL, tarif_reference_montant NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, etablissement_id BINARY(16) NOT NULL, produit_tarif_reference_id BINARY(16) DEFAULT NULL, INDEX IDX_25C0B701FF631228 (etablissement_id), INDEX IDX_25C0B701398767D2 (produit_tarif_reference_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_creneau (id BINARY(16) NOT NULL, debut DATETIME NOT NULL, fin DATETIME NOT NULL, capacite SMALLINT NOT NULL, statut VARCHAR(10) DEFAULT \'planifie\' NOT NULL, public_reserve VARCHAR(80) DEFAULT NULL, occurrence_modifiee TINYINT DEFAULT 0 NOT NULL, en_attente_arbitrage TINYINT DEFAULT 0 NOT NULL, ressource_id BINARY(16) NOT NULL, activite_id BINARY(16) DEFAULT NULL, recurrence_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_9EF330DAFC6CD52A (ressource_id), INDEX IDX_9EF330DA9B0F88B1 (activite_id), INDEX IDX_9EF330DA2C414CE8 (recurrence_id), INDEX IDX_9EF330DAFF631228 (etablissement_id), INDEX idx_creneau_ressource_periode (ressource_id, debut, fin), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_disponibilite (id BINARY(16) NOT NULL, jour_semaine SMALLINT NOT NULL, heure_debut TIME NOT NULL, heure_fin TIME NOT NULL, ressource_id BINARY(16) NOT NULL, INDEX IDX_C2D6BE72FC6CD52A (ressource_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_emargement (id BINARY(16) NOT NULL, statut VARCHAR(8) NOT NULL, horodatage DATETIME NOT NULL, compte_rendu LONGTEXT DEFAULT NULL, reservation_id BINARY(16) NOT NULL, operateur_id BINARY(16) DEFAULT NULL, INDEX IDX_11117BE4B83297E7 (reservation_id), INDEX IDX_11117BE43F192FC (operateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_facturation_no_show (id BINARY(16) NOT NULL, montant NUMERIC(10, 2) NOT NULL, statut VARCHAR(12) DEFAULT \'a_facturer\' NOT NULL, reference_echeance_sepa VARCHAR(64) DEFAULT NULL, motif_exoneration VARCHAR(255) DEFAULT NULL, reservation_id BINARY(16) NOT NULL, regle_appliquee_id BINARY(16) NOT NULL, vente_rattachee_id BINARY(16) DEFAULT NULL, exonere_par_id BINARY(16) DEFAULT NULL, INDEX IDX_C6A4A9A1D2AE8B1E (regle_appliquee_id), INDEX IDX_C6A4A9A11977E20D (vente_rattachee_id), INDEX IDX_C6A4A9A1321D4DC2 (exonere_par_id), UNIQUE INDEX uniq_facturation_no_show_reservation (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_indisponibilite (id BINARY(16) NOT NULL, debut DATETIME NOT NULL, fin DATETIME NOT NULL, motif VARCHAR(255) DEFAULT NULL, ressource_id BINARY(16) NOT NULL, INDEX IDX_D807BF26FC6CD52A (ressource_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_liste_attente (id BINARY(16) NOT NULL, rang SMALLINT NOT NULL, date_inscription DATETIME NOT NULL, statut VARCHAR(12) DEFAULT \'en_attente\' NOT NULL, date_expiration_promotion DATETIME DEFAULT NULL, creneau_id BINARY(16) NOT NULL, beneficiaire_id BINARY(16) NOT NULL, promue_en_id BINARY(16) DEFAULT NULL, INDEX IDX_4B20B1C57D0729A9 (creneau_id), INDEX IDX_4B20B1C55AF81F68 (beneficiaire_id), INDEX IDX_4B20B1C5A7DCB71C (promue_en_id), UNIQUE INDEX uniq_liste_attente_creneau_rang (creneau_id, rang), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_participant (id BINARY(16) NOT NULL, est_organisateur TINYINT DEFAULT 0 NOT NULL, part_montant NUMERIC(10, 2) NOT NULL, statut_paiement VARCHAR(20) DEFAULT \'en_attente\' NOT NULL, reservation_id BINARY(16) NOT NULL, personne_id BINARY(16) NOT NULL, INDEX IDX_1BF3B64DB83297E7 (reservation_id), INDEX IDX_1BF3B64DA21BD112 (personne_id), UNIQUE INDEX uniq_participant_reservation_personne (reservation_id, personne_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_projection_acces (id BINARY(16) NOT NULL, droit_acces_ref BINARY(16) DEFAULT NULL, fenetre_debut DATETIME NOT NULL, fenetre_fin DATETIME NOT NULL, marge_avance_minutes INT DEFAULT NULL, marge_retard_minutes INT DEFAULT NULL, reservation_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_3354F7E2FF631228 (etablissement_id), UNIQUE INDEX uniq_projection_acces_reservation (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_recurrence (id BINARY(16) NOT NULL, motif VARCHAR(16) NOT NULL, fin_recurrence DATE NOT NULL, jours_semaine JSON DEFAULT NULL, regle_conflit VARCHAR(20) DEFAULT \'report_auto\' NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_7F1D3B95FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_regle_annulation (id BINARY(16) NOT NULL, portee VARCHAR(14) NOT NULL, cible_type_ressource VARCHAR(40) DEFAULT NULL, delai_franc_minutes INT NOT NULL, mode_montant VARCHAR(11) NOT NULL, valeur_montant NUMERIC(10, 2) NOT NULL, exonerations JSON DEFAULT NULL, mode_facturation VARCHAR(20) NOT NULL, marge_post_creneau_minutes INT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, etablissement_id BINARY(16) NOT NULL, cible_ressource_id BINARY(16) DEFAULT NULL, cible_activite_id BINARY(16) DEFAULT NULL, INDEX IDX_D90AE8BFF631228 (etablissement_id), INDEX IDX_D90AE8B60125D0A (cible_ressource_id), INDEX IDX_D90AE8BEC810175 (cible_activite_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_reservation (id BINARY(16) NOT NULL, statut VARCHAR(24) DEFAULT \'confirmee\' NOT NULL, date_creation DATETIME NOT NULL, mode_decompte VARCHAR(14) NOT NULL, montant_du NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, date_limite_annulation DATETIME DEFAULT NULL, presence_confirmee TINYINT DEFAULT 0 NOT NULL, date_confirmation_presence DATETIME DEFAULT NULL, source_presence VARCHAR(18) DEFAULT NULL, creneau_id BINARY(16) NOT NULL, organisateur_id BINARY(16) NOT NULL, service_inclus_ref_id BINARY(16) DEFAULT NULL, vente_rattachee_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_8EA494097D0729A9 (creneau_id), INDEX IDX_8EA49409D936B2FA (organisateur_id), INDEX IDX_8EA494094820EEDA (service_inclus_ref_id), INDEX IDX_8EA494091977E20D (vente_rattachee_id), INDEX IDX_8EA49409FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_ressource (id BINARY(16) NOT NULL, code_type VARCHAR(40) NOT NULL, libelle VARCHAR(120) NOT NULL, capacite_propre SMALLINT NOT NULL, partageable TINYINT DEFAULT 0 NOT NULL, competence_requise VARCHAR(80) DEFAULT NULL, occupation_courante INT DEFAULT 0 NOT NULL, ouvre_acces TINYINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, etablissement_id BINARY(16) NOT NULL, espace_id BINARY(16) DEFAULT NULL, ressource_mere_id BINARY(16) DEFAULT NULL, INDEX IDX_89D824DBFF631228 (etablissement_id), INDEX IDX_89D824DBB6885C6C (espace_id), INDEX IDX_89D824DB8CE44AEC (ressource_mere_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reservation_activite ADD CONSTRAINT FK_25C0B701FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE reservation_activite ADD CONSTRAINT FK_25C0B701398767D2 FOREIGN KEY (produit_tarif_reference_id) REFERENCES off_produit (id)');
        $this->addSql('ALTER TABLE reservation_creneau ADD CONSTRAINT FK_9EF330DAFC6CD52A FOREIGN KEY (ressource_id) REFERENCES reservation_ressource (id)');
        $this->addSql('ALTER TABLE reservation_creneau ADD CONSTRAINT FK_9EF330DA9B0F88B1 FOREIGN KEY (activite_id) REFERENCES reservation_activite (id)');
        $this->addSql('ALTER TABLE reservation_creneau ADD CONSTRAINT FK_9EF330DA2C414CE8 FOREIGN KEY (recurrence_id) REFERENCES reservation_recurrence (id)');
        $this->addSql('ALTER TABLE reservation_creneau ADD CONSTRAINT FK_9EF330DAFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE reservation_disponibilite ADD CONSTRAINT FK_C2D6BE72FC6CD52A FOREIGN KEY (ressource_id) REFERENCES reservation_ressource (id)');
        $this->addSql('ALTER TABLE reservation_emargement ADD CONSTRAINT FK_11117BE4B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE reservation_emargement ADD CONSTRAINT FK_11117BE43F192FC FOREIGN KEY (operateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE reservation_facturation_no_show ADD CONSTRAINT FK_C6A4A9A1B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE reservation_facturation_no_show ADD CONSTRAINT FK_C6A4A9A1D2AE8B1E FOREIGN KEY (regle_appliquee_id) REFERENCES reservation_regle_annulation (id)');
        $this->addSql('ALTER TABLE reservation_facturation_no_show ADD CONSTRAINT FK_C6A4A9A11977E20D FOREIGN KEY (vente_rattachee_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE reservation_facturation_no_show ADD CONSTRAINT FK_C6A4A9A1321D4DC2 FOREIGN KEY (exonere_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE reservation_indisponibilite ADD CONSTRAINT FK_D807BF26FC6CD52A FOREIGN KEY (ressource_id) REFERENCES reservation_ressource (id)');
        $this->addSql('ALTER TABLE reservation_liste_attente ADD CONSTRAINT FK_4B20B1C57D0729A9 FOREIGN KEY (creneau_id) REFERENCES reservation_creneau (id)');
        $this->addSql('ALTER TABLE reservation_liste_attente ADD CONSTRAINT FK_4B20B1C55AF81F68 FOREIGN KEY (beneficiaire_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE reservation_liste_attente ADD CONSTRAINT FK_4B20B1C5A7DCB71C FOREIGN KEY (promue_en_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE reservation_participant ADD CONSTRAINT FK_1BF3B64DB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE reservation_participant ADD CONSTRAINT FK_1BF3B64DA21BD112 FOREIGN KEY (personne_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE reservation_projection_acces ADD CONSTRAINT FK_3354F7E2B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE reservation_projection_acces ADD CONSTRAINT FK_3354F7E2FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE reservation_recurrence ADD CONSTRAINT FK_7F1D3B95FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE reservation_regle_annulation ADD CONSTRAINT FK_D90AE8BFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE reservation_regle_annulation ADD CONSTRAINT FK_D90AE8B60125D0A FOREIGN KEY (cible_ressource_id) REFERENCES reservation_ressource (id)');
        $this->addSql('ALTER TABLE reservation_regle_annulation ADD CONSTRAINT FK_D90AE8BEC810175 FOREIGN KEY (cible_activite_id) REFERENCES reservation_activite (id)');
        $this->addSql('ALTER TABLE reservation_reservation ADD CONSTRAINT FK_8EA494097D0729A9 FOREIGN KEY (creneau_id) REFERENCES reservation_creneau (id)');
        $this->addSql('ALTER TABLE reservation_reservation ADD CONSTRAINT FK_8EA49409D936B2FA FOREIGN KEY (organisateur_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE reservation_reservation ADD CONSTRAINT FK_8EA494094820EEDA FOREIGN KEY (service_inclus_ref_id) REFERENCES off_service_inclus (id)');
        $this->addSql('ALTER TABLE reservation_reservation ADD CONSTRAINT FK_8EA494091977E20D FOREIGN KEY (vente_rattachee_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE reservation_reservation ADD CONSTRAINT FK_8EA49409FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE reservation_ressource ADD CONSTRAINT FK_89D824DBFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE reservation_ressource ADD CONSTRAINT FK_89D824DBB6885C6C FOREIGN KEY (espace_id) REFERENCES org_espace (id)');
        $this->addSql('ALTER TABLE reservation_ressource ADD CONSTRAINT FK_89D824DB8CE44AEC FOREIGN KEY (ressource_mere_id) REFERENCES reservation_ressource (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation_activite DROP FOREIGN KEY FK_25C0B701FF631228');
        $this->addSql('ALTER TABLE reservation_activite DROP FOREIGN KEY FK_25C0B701398767D2');
        $this->addSql('ALTER TABLE reservation_creneau DROP FOREIGN KEY FK_9EF330DAFC6CD52A');
        $this->addSql('ALTER TABLE reservation_creneau DROP FOREIGN KEY FK_9EF330DA9B0F88B1');
        $this->addSql('ALTER TABLE reservation_creneau DROP FOREIGN KEY FK_9EF330DA2C414CE8');
        $this->addSql('ALTER TABLE reservation_creneau DROP FOREIGN KEY FK_9EF330DAFF631228');
        $this->addSql('ALTER TABLE reservation_disponibilite DROP FOREIGN KEY FK_C2D6BE72FC6CD52A');
        $this->addSql('ALTER TABLE reservation_emargement DROP FOREIGN KEY FK_11117BE4B83297E7');
        $this->addSql('ALTER TABLE reservation_emargement DROP FOREIGN KEY FK_11117BE43F192FC');
        $this->addSql('ALTER TABLE reservation_facturation_no_show DROP FOREIGN KEY FK_C6A4A9A1B83297E7');
        $this->addSql('ALTER TABLE reservation_facturation_no_show DROP FOREIGN KEY FK_C6A4A9A1D2AE8B1E');
        $this->addSql('ALTER TABLE reservation_facturation_no_show DROP FOREIGN KEY FK_C6A4A9A11977E20D');
        $this->addSql('ALTER TABLE reservation_facturation_no_show DROP FOREIGN KEY FK_C6A4A9A1321D4DC2');
        $this->addSql('ALTER TABLE reservation_indisponibilite DROP FOREIGN KEY FK_D807BF26FC6CD52A');
        $this->addSql('ALTER TABLE reservation_liste_attente DROP FOREIGN KEY FK_4B20B1C57D0729A9');
        $this->addSql('ALTER TABLE reservation_liste_attente DROP FOREIGN KEY FK_4B20B1C55AF81F68');
        $this->addSql('ALTER TABLE reservation_liste_attente DROP FOREIGN KEY FK_4B20B1C5A7DCB71C');
        $this->addSql('ALTER TABLE reservation_participant DROP FOREIGN KEY FK_1BF3B64DB83297E7');
        $this->addSql('ALTER TABLE reservation_participant DROP FOREIGN KEY FK_1BF3B64DA21BD112');
        $this->addSql('ALTER TABLE reservation_projection_acces DROP FOREIGN KEY FK_3354F7E2B83297E7');
        $this->addSql('ALTER TABLE reservation_projection_acces DROP FOREIGN KEY FK_3354F7E2FF631228');
        $this->addSql('ALTER TABLE reservation_recurrence DROP FOREIGN KEY FK_7F1D3B95FF631228');
        $this->addSql('ALTER TABLE reservation_regle_annulation DROP FOREIGN KEY FK_D90AE8BFF631228');
        $this->addSql('ALTER TABLE reservation_regle_annulation DROP FOREIGN KEY FK_D90AE8B60125D0A');
        $this->addSql('ALTER TABLE reservation_regle_annulation DROP FOREIGN KEY FK_D90AE8BEC810175');
        $this->addSql('ALTER TABLE reservation_reservation DROP FOREIGN KEY FK_8EA494097D0729A9');
        $this->addSql('ALTER TABLE reservation_reservation DROP FOREIGN KEY FK_8EA49409D936B2FA');
        $this->addSql('ALTER TABLE reservation_reservation DROP FOREIGN KEY FK_8EA494094820EEDA');
        $this->addSql('ALTER TABLE reservation_reservation DROP FOREIGN KEY FK_8EA494091977E20D');
        $this->addSql('ALTER TABLE reservation_reservation DROP FOREIGN KEY FK_8EA49409FF631228');
        $this->addSql('ALTER TABLE reservation_ressource DROP FOREIGN KEY FK_89D824DBFF631228');
        $this->addSql('ALTER TABLE reservation_ressource DROP FOREIGN KEY FK_89D824DBB6885C6C');
        $this->addSql('ALTER TABLE reservation_ressource DROP FOREIGN KEY FK_89D824DB8CE44AEC');
        $this->addSql('DROP TABLE reservation_activite');
        $this->addSql('DROP TABLE reservation_creneau');
        $this->addSql('DROP TABLE reservation_disponibilite');
        $this->addSql('DROP TABLE reservation_emargement');
        $this->addSql('DROP TABLE reservation_facturation_no_show');
        $this->addSql('DROP TABLE reservation_indisponibilite');
        $this->addSql('DROP TABLE reservation_liste_attente');
        $this->addSql('DROP TABLE reservation_participant');
        $this->addSql('DROP TABLE reservation_projection_acces');
        $this->addSql('DROP TABLE reservation_recurrence');
        $this->addSql('DROP TABLE reservation_regle_annulation');
        $this->addSql('DROP TABLE reservation_reservation');
        $this->addSql('DROP TABLE reservation_ressource');
    }
}
