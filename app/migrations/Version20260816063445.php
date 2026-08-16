<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260816063445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verticale Musée (App\\Musee) : ParametreMuseeEtablissement, Salle/SousQuotaSalle/PolitiqueDelestage (overlay EspaceAcces L3), Exposition/Audioguide (satellites Produit M1), Guide/QualificationLangueGuide, VisiteGuidee (overlay Creneau Réservation), BasculeAudioguide, ContingentGratuite/DossierGroupeScolaire/Gratuite, PartenaireOTA/AllocationQuotaOTA/ReservationOTA/Reversement, PassAnnuel (satellite Formule M1).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE musee_allocation_quota_ota (id BINARY(16) NOT NULL, quota_alloue INT NOT NULL, quota_consomme INT DEFAULT 0 NOT NULL, partenaire_id BINARY(16) NOT NULL, creneau_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_DC3112D98DE13AC (partenaire_id), INDEX IDX_DC3112D7D0729A9 (creneau_id), INDEX IDX_DC3112DFF631228 (etablissement_id), UNIQUE INDEX uniq_allocation_partenaire_creneau (partenaire_id, creneau_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_audioguide (id BINARY(16) NOT NULL, langues JSON NOT NULL, produit_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_C8EEE81FF631228 (etablissement_id), UNIQUE INDEX uniq_audioguide_produit (produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_bascule_audioguide (id BINARY(16) NOT NULL, taux_remise NUMERIC(5, 2) NOT NULL, cree_le DATETIME NOT NULL, visite_guidee_ref_initiale_id BINARY(16) DEFAULT NULL, audioguide_id BINARY(16) NOT NULL, beneficiaire_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_D09B51F170200052 (visite_guidee_ref_initiale_id), INDEX IDX_D09B51F19C12F5EA (audioguide_id), INDEX IDX_D09B51F15AF81F68 (beneficiaire_id), INDEX IDX_D09B51F1FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_contingent_gratuite (id BINARY(16) NOT NULL, perimetre VARCHAR(11) NOT NULL, quota_gratuites_dedie INT NOT NULL, quota_consomme INT DEFAULT 0 NOT NULL, exposition_id BINARY(16) DEFAULT NULL, creneau_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_E2DF68FE88ED476F (exposition_id), INDEX IDX_E2DF68FE7D0729A9 (creneau_id), INDEX IDX_E2DF68FEFF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_dossier_groupe_scolaire (id BINARY(16) NOT NULL, etablissement_scolaire VARCHAR(255) NOT NULL, effectif INT NOT NULL, accompagnateurs INT DEFAULT 0 NOT NULL, date_option DATE DEFAULT NULL, statut_paiement VARCHAR(18) DEFAULT \'en_option\' NOT NULL, creneau_entree_id BINARY(16) NOT NULL, vente_rattachee_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_FC4CCA9CA99AED6C (creneau_entree_id), INDEX IDX_FC4CCA9C1977E20D (vente_rattachee_id), INDEX IDX_FC4CCA9CFF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_dossier_guide (dossier_groupe_scolaire_id BINARY(16) NOT NULL, guide_id BINARY(16) NOT NULL, INDEX IDX_D347EC567C9E81C7 (dossier_groupe_scolaire_id), INDEX IDX_D347EC56D7ED1D4B (guide_id), PRIMARY KEY (dossier_groupe_scolaire_id, guide_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_exposition (id BINARY(16) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, a_jauge TINYINT DEFAULT 0 NOT NULL, jauge_globale INT DEFAULT NULL, produit_id BINARY(16) NOT NULL, ressource_entree_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_68B5B8E0C9335363 (ressource_entree_id), INDEX IDX_68B5B8E0FF631228 (etablissement_id), UNIQUE INDEX uniq_exposition_produit (produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_gratuite (id BINARY(16) NOT NULL, motif VARCHAR(16) NOT NULL, dossier_id BINARY(16) NOT NULL, contingent_id BINARY(16) NOT NULL, reservation_rattachee_id BINARY(16) NOT NULL, INDEX IDX_A67A1ABB611C0C56 (dossier_id), INDEX IDX_A67A1ABB635D1086 (contingent_id), UNIQUE INDEX uniq_gratuite_reservation (reservation_rattachee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_guide (id BINARY(16) NOT NULL, utilisateur_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_99689C88FB88E14F (utilisateur_id), INDEX IDX_99689C88FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_parametre_etablissement (id BINARY(16) NOT NULL, seuil_pastille_tendu SMALLINT DEFAULT 20 NOT NULL, taux_remise_audioguide_defaut NUMERIC(5, 2) DEFAULT \'20.00\' NOT NULL, delai_option_dossier_groupe_jours SMALLINT DEFAULT 15 NOT NULL, mode_sous_quota_salle_defaut VARCHAR(8) DEFAULT \'alerte\' NOT NULL, etablissement_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_parametre_musee_etablissement (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_partenaire_ota (id BINARY(16) NOT NULL, nom VARCHAR(120) NOT NULL, tarif_net NUMERIC(10, 2) NOT NULL, commission NUMERIC(5, 2) NOT NULL, code_connecteur VARCHAR(60) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_45D35C90FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_pass_annuel (id BINARY(16) NOT NULL, echeance DATE NOT NULL, avantages JSON DEFAULT \'[]\' NOT NULL, formule_id BINARY(16) NOT NULL, adherent_id BINARY(16) NOT NULL, support_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_816BD64625F06C53 (adherent_id), INDEX IDX_816BD646FF631228 (etablissement_id), UNIQUE INDEX uniq_pass_annuel_formule (formule_id), UNIQUE INDEX uniq_pass_annuel_support (support_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_politique_delestage (id BINARY(16) NOT NULL, mode VARCHAR(24) DEFAULT \'alerte_seule\' NOT NULL, message_agent VARCHAR(255) DEFAULT NULL, sous_quota_salle_id BINARY(16) NOT NULL, salle_redirection_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_1F0CC2EE9B6D6F7D (salle_redirection_id), INDEX IDX_1F0CC2EEFF631228 (etablissement_id), UNIQUE INDEX uniq_politique_delestage_sous_quota (sous_quota_salle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_qualification_langue_guide (id BINARY(16) NOT NULL, langue VARCHAR(8) NOT NULL, guide_id BINARY(16) NOT NULL, INDEX IDX_3F6FEE17D7ED1D4B (guide_id), UNIQUE INDEX uniq_qualif_guide_langue (guide_id, langue), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_reservation_ota (id BINARY(16) NOT NULL, horodatage_confirmation DATETIME NOT NULL, statut_ota VARCHAR(20) DEFAULT \'confirmee\' NOT NULL, allocation_id BINARY(16) NOT NULL, reservation_rattachee_id BINARY(16) NOT NULL, INDEX IDX_3818A4299C83F4B2 (allocation_id), UNIQUE INDEX uniq_resa_ota_reservation (reservation_rattachee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_reversement (id BINARY(16) NOT NULL, periode_debut DATE NOT NULL, periode_fin DATE NOT NULL, montant NUMERIC(10, 2) NOT NULL, statut VARCHAR(10) DEFAULT \'a_verser\' NOT NULL, partenaire_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_490035C098DE13AC (partenaire_id), INDEX IDX_490035C0FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_salle (id BINARY(16) NOT NULL, nom VARCHAR(120) NOT NULL, espace_id BINARY(16) NOT NULL, espace_acces_id BINARY(16) DEFAULT NULL, exposition_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_1D6125E1B6885C6C (espace_id), INDEX IDX_1D6125E188ED476F (exposition_id), INDEX IDX_1D6125E1FF631228 (etablissement_id), UNIQUE INDEX uniq_salle_espace_acces (espace_acces_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_sous_quota_salle (id BINARY(16) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, salle_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_1CB0A413FF631228 (etablissement_id), UNIQUE INDEX uniq_sous_quota_salle (salle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE musee_visite_guidee (id BINARY(16) NOT NULL, theme VARCHAR(120) NOT NULL, langue VARCHAR(8) NOT NULL, point_rdv VARCHAR(255) NOT NULL, statut VARCHAR(10) DEFAULT \'planifiee\' NOT NULL, guide_id BINARY(16) DEFAULT NULL, creneau_visite_id BINARY(16) NOT NULL, creneau_entree_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_D24DBD6BD7ED1D4B (guide_id), INDEX IDX_D24DBD6BA99AED6C (creneau_entree_id), INDEX IDX_D24DBD6BFF631228 (etablissement_id), UNIQUE INDEX uniq_visite_creneau_visite (creneau_visite_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE musee_allocation_quota_ota ADD CONSTRAINT FK_DC3112D98DE13AC FOREIGN KEY (partenaire_id) REFERENCES musee_partenaire_ota (id)');
        $this->addSql('ALTER TABLE musee_allocation_quota_ota ADD CONSTRAINT FK_DC3112D7D0729A9 FOREIGN KEY (creneau_id) REFERENCES reservation_creneau (id)');
        $this->addSql('ALTER TABLE musee_allocation_quota_ota ADD CONSTRAINT FK_DC3112DFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_audioguide ADD CONSTRAINT FK_C8EEE81F347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id)');
        $this->addSql('ALTER TABLE musee_audioguide ADD CONSTRAINT FK_C8EEE81FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_bascule_audioguide ADD CONSTRAINT FK_D09B51F170200052 FOREIGN KEY (visite_guidee_ref_initiale_id) REFERENCES musee_visite_guidee (id)');
        $this->addSql('ALTER TABLE musee_bascule_audioguide ADD CONSTRAINT FK_D09B51F19C12F5EA FOREIGN KEY (audioguide_id) REFERENCES musee_audioguide (id)');
        $this->addSql('ALTER TABLE musee_bascule_audioguide ADD CONSTRAINT FK_D09B51F15AF81F68 FOREIGN KEY (beneficiaire_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE musee_bascule_audioguide ADD CONSTRAINT FK_D09B51F1FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_contingent_gratuite ADD CONSTRAINT FK_E2DF68FE88ED476F FOREIGN KEY (exposition_id) REFERENCES musee_exposition (id)');
        $this->addSql('ALTER TABLE musee_contingent_gratuite ADD CONSTRAINT FK_E2DF68FE7D0729A9 FOREIGN KEY (creneau_id) REFERENCES reservation_creneau (id)');
        $this->addSql('ALTER TABLE musee_contingent_gratuite ADD CONSTRAINT FK_E2DF68FEFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_dossier_groupe_scolaire ADD CONSTRAINT FK_FC4CCA9CA99AED6C FOREIGN KEY (creneau_entree_id) REFERENCES reservation_creneau (id)');
        $this->addSql('ALTER TABLE musee_dossier_groupe_scolaire ADD CONSTRAINT FK_FC4CCA9C1977E20D FOREIGN KEY (vente_rattachee_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE musee_dossier_groupe_scolaire ADD CONSTRAINT FK_FC4CCA9CFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_dossier_guide ADD CONSTRAINT FK_D347EC567C9E81C7 FOREIGN KEY (dossier_groupe_scolaire_id) REFERENCES musee_dossier_groupe_scolaire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE musee_dossier_guide ADD CONSTRAINT FK_D347EC56D7ED1D4B FOREIGN KEY (guide_id) REFERENCES musee_guide (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE musee_exposition ADD CONSTRAINT FK_68B5B8E0F347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id)');
        $this->addSql('ALTER TABLE musee_exposition ADD CONSTRAINT FK_68B5B8E0C9335363 FOREIGN KEY (ressource_entree_id) REFERENCES reservation_ressource (id)');
        $this->addSql('ALTER TABLE musee_exposition ADD CONSTRAINT FK_68B5B8E0FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_gratuite ADD CONSTRAINT FK_A67A1ABB611C0C56 FOREIGN KEY (dossier_id) REFERENCES musee_dossier_groupe_scolaire (id)');
        $this->addSql('ALTER TABLE musee_gratuite ADD CONSTRAINT FK_A67A1ABB635D1086 FOREIGN KEY (contingent_id) REFERENCES musee_contingent_gratuite (id)');
        $this->addSql('ALTER TABLE musee_gratuite ADD CONSTRAINT FK_A67A1ABB9490CE81 FOREIGN KEY (reservation_rattachee_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE musee_guide ADD CONSTRAINT FK_99689C88FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE musee_guide ADD CONSTRAINT FK_99689C88FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_parametre_etablissement ADD CONSTRAINT FK_67B09CBFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_partenaire_ota ADD CONSTRAINT FK_45D35C90FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_pass_annuel ADD CONSTRAINT FK_816BD6462A68F4D1 FOREIGN KEY (formule_id) REFERENCES off_formule (id)');
        $this->addSql('ALTER TABLE musee_pass_annuel ADD CONSTRAINT FK_816BD64625F06C53 FOREIGN KEY (adherent_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE musee_pass_annuel ADD CONSTRAINT FK_816BD646315B405 FOREIGN KEY (support_id) REFERENCES acces_support (id)');
        $this->addSql('ALTER TABLE musee_pass_annuel ADD CONSTRAINT FK_816BD646FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_politique_delestage ADD CONSTRAINT FK_1F0CC2EE42DBB16A FOREIGN KEY (sous_quota_salle_id) REFERENCES musee_sous_quota_salle (id)');
        $this->addSql('ALTER TABLE musee_politique_delestage ADD CONSTRAINT FK_1F0CC2EE9B6D6F7D FOREIGN KEY (salle_redirection_id) REFERENCES musee_salle (id)');
        $this->addSql('ALTER TABLE musee_politique_delestage ADD CONSTRAINT FK_1F0CC2EEFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_qualification_langue_guide ADD CONSTRAINT FK_3F6FEE17D7ED1D4B FOREIGN KEY (guide_id) REFERENCES musee_guide (id)');
        $this->addSql('ALTER TABLE musee_reservation_ota ADD CONSTRAINT FK_3818A4299C83F4B2 FOREIGN KEY (allocation_id) REFERENCES musee_allocation_quota_ota (id)');
        $this->addSql('ALTER TABLE musee_reservation_ota ADD CONSTRAINT FK_3818A4299490CE81 FOREIGN KEY (reservation_rattachee_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE musee_reversement ADD CONSTRAINT FK_490035C098DE13AC FOREIGN KEY (partenaire_id) REFERENCES musee_partenaire_ota (id)');
        $this->addSql('ALTER TABLE musee_reversement ADD CONSTRAINT FK_490035C0FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_salle ADD CONSTRAINT FK_1D6125E1B6885C6C FOREIGN KEY (espace_id) REFERENCES org_espace (id)');
        $this->addSql('ALTER TABLE musee_salle ADD CONSTRAINT FK_1D6125E1F353E39C FOREIGN KEY (espace_acces_id) REFERENCES acces_espace_acces (id)');
        $this->addSql('ALTER TABLE musee_salle ADD CONSTRAINT FK_1D6125E188ED476F FOREIGN KEY (exposition_id) REFERENCES musee_exposition (id)');
        $this->addSql('ALTER TABLE musee_salle ADD CONSTRAINT FK_1D6125E1FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_sous_quota_salle ADD CONSTRAINT FK_1CB0A413DC304035 FOREIGN KEY (salle_id) REFERENCES musee_salle (id)');
        $this->addSql('ALTER TABLE musee_sous_quota_salle ADD CONSTRAINT FK_1CB0A413FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE musee_visite_guidee ADD CONSTRAINT FK_D24DBD6BD7ED1D4B FOREIGN KEY (guide_id) REFERENCES musee_guide (id)');
        $this->addSql('ALTER TABLE musee_visite_guidee ADD CONSTRAINT FK_D24DBD6BC724E825 FOREIGN KEY (creneau_visite_id) REFERENCES reservation_creneau (id)');
        $this->addSql('ALTER TABLE musee_visite_guidee ADD CONSTRAINT FK_D24DBD6BA99AED6C FOREIGN KEY (creneau_entree_id) REFERENCES reservation_creneau (id)');
        $this->addSql('ALTER TABLE musee_visite_guidee ADD CONSTRAINT FK_D24DBD6BFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE musee_allocation_quota_ota DROP FOREIGN KEY FK_DC3112D98DE13AC');
        $this->addSql('ALTER TABLE musee_allocation_quota_ota DROP FOREIGN KEY FK_DC3112D7D0729A9');
        $this->addSql('ALTER TABLE musee_allocation_quota_ota DROP FOREIGN KEY FK_DC3112DFF631228');
        $this->addSql('ALTER TABLE musee_audioguide DROP FOREIGN KEY FK_C8EEE81F347EFB');
        $this->addSql('ALTER TABLE musee_audioguide DROP FOREIGN KEY FK_C8EEE81FF631228');
        $this->addSql('ALTER TABLE musee_bascule_audioguide DROP FOREIGN KEY FK_D09B51F170200052');
        $this->addSql('ALTER TABLE musee_bascule_audioguide DROP FOREIGN KEY FK_D09B51F19C12F5EA');
        $this->addSql('ALTER TABLE musee_bascule_audioguide DROP FOREIGN KEY FK_D09B51F15AF81F68');
        $this->addSql('ALTER TABLE musee_bascule_audioguide DROP FOREIGN KEY FK_D09B51F1FF631228');
        $this->addSql('ALTER TABLE musee_contingent_gratuite DROP FOREIGN KEY FK_E2DF68FE88ED476F');
        $this->addSql('ALTER TABLE musee_contingent_gratuite DROP FOREIGN KEY FK_E2DF68FE7D0729A9');
        $this->addSql('ALTER TABLE musee_contingent_gratuite DROP FOREIGN KEY FK_E2DF68FEFF631228');
        $this->addSql('ALTER TABLE musee_dossier_groupe_scolaire DROP FOREIGN KEY FK_FC4CCA9CA99AED6C');
        $this->addSql('ALTER TABLE musee_dossier_groupe_scolaire DROP FOREIGN KEY FK_FC4CCA9C1977E20D');
        $this->addSql('ALTER TABLE musee_dossier_groupe_scolaire DROP FOREIGN KEY FK_FC4CCA9CFF631228');
        $this->addSql('ALTER TABLE musee_dossier_guide DROP FOREIGN KEY FK_D347EC567C9E81C7');
        $this->addSql('ALTER TABLE musee_dossier_guide DROP FOREIGN KEY FK_D347EC56D7ED1D4B');
        $this->addSql('ALTER TABLE musee_exposition DROP FOREIGN KEY FK_68B5B8E0F347EFB');
        $this->addSql('ALTER TABLE musee_exposition DROP FOREIGN KEY FK_68B5B8E0C9335363');
        $this->addSql('ALTER TABLE musee_exposition DROP FOREIGN KEY FK_68B5B8E0FF631228');
        $this->addSql('ALTER TABLE musee_gratuite DROP FOREIGN KEY FK_A67A1ABB611C0C56');
        $this->addSql('ALTER TABLE musee_gratuite DROP FOREIGN KEY FK_A67A1ABB635D1086');
        $this->addSql('ALTER TABLE musee_gratuite DROP FOREIGN KEY FK_A67A1ABB9490CE81');
        $this->addSql('ALTER TABLE musee_guide DROP FOREIGN KEY FK_99689C88FB88E14F');
        $this->addSql('ALTER TABLE musee_guide DROP FOREIGN KEY FK_99689C88FF631228');
        $this->addSql('ALTER TABLE musee_parametre_etablissement DROP FOREIGN KEY FK_67B09CBFF631228');
        $this->addSql('ALTER TABLE musee_partenaire_ota DROP FOREIGN KEY FK_45D35C90FF631228');
        $this->addSql('ALTER TABLE musee_pass_annuel DROP FOREIGN KEY FK_816BD6462A68F4D1');
        $this->addSql('ALTER TABLE musee_pass_annuel DROP FOREIGN KEY FK_816BD64625F06C53');
        $this->addSql('ALTER TABLE musee_pass_annuel DROP FOREIGN KEY FK_816BD646315B405');
        $this->addSql('ALTER TABLE musee_pass_annuel DROP FOREIGN KEY FK_816BD646FF631228');
        $this->addSql('ALTER TABLE musee_politique_delestage DROP FOREIGN KEY FK_1F0CC2EE42DBB16A');
        $this->addSql('ALTER TABLE musee_politique_delestage DROP FOREIGN KEY FK_1F0CC2EE9B6D6F7D');
        $this->addSql('ALTER TABLE musee_politique_delestage DROP FOREIGN KEY FK_1F0CC2EEFF631228');
        $this->addSql('ALTER TABLE musee_qualification_langue_guide DROP FOREIGN KEY FK_3F6FEE17D7ED1D4B');
        $this->addSql('ALTER TABLE musee_reservation_ota DROP FOREIGN KEY FK_3818A4299C83F4B2');
        $this->addSql('ALTER TABLE musee_reservation_ota DROP FOREIGN KEY FK_3818A4299490CE81');
        $this->addSql('ALTER TABLE musee_reversement DROP FOREIGN KEY FK_490035C098DE13AC');
        $this->addSql('ALTER TABLE musee_reversement DROP FOREIGN KEY FK_490035C0FF631228');
        $this->addSql('ALTER TABLE musee_salle DROP FOREIGN KEY FK_1D6125E1B6885C6C');
        $this->addSql('ALTER TABLE musee_salle DROP FOREIGN KEY FK_1D6125E1F353E39C');
        $this->addSql('ALTER TABLE musee_salle DROP FOREIGN KEY FK_1D6125E188ED476F');
        $this->addSql('ALTER TABLE musee_salle DROP FOREIGN KEY FK_1D6125E1FF631228');
        $this->addSql('ALTER TABLE musee_sous_quota_salle DROP FOREIGN KEY FK_1CB0A413DC304035');
        $this->addSql('ALTER TABLE musee_sous_quota_salle DROP FOREIGN KEY FK_1CB0A413FF631228');
        $this->addSql('ALTER TABLE musee_visite_guidee DROP FOREIGN KEY FK_D24DBD6BD7ED1D4B');
        $this->addSql('ALTER TABLE musee_visite_guidee DROP FOREIGN KEY FK_D24DBD6BC724E825');
        $this->addSql('ALTER TABLE musee_visite_guidee DROP FOREIGN KEY FK_D24DBD6BA99AED6C');
        $this->addSql('ALTER TABLE musee_visite_guidee DROP FOREIGN KEY FK_D24DBD6BFF631228');
        $this->addSql('DROP TABLE musee_allocation_quota_ota');
        $this->addSql('DROP TABLE musee_audioguide');
        $this->addSql('DROP TABLE musee_bascule_audioguide');
        $this->addSql('DROP TABLE musee_contingent_gratuite');
        $this->addSql('DROP TABLE musee_dossier_groupe_scolaire');
        $this->addSql('DROP TABLE musee_dossier_guide');
        $this->addSql('DROP TABLE musee_exposition');
        $this->addSql('DROP TABLE musee_gratuite');
        $this->addSql('DROP TABLE musee_guide');
        $this->addSql('DROP TABLE musee_parametre_etablissement');
        $this->addSql('DROP TABLE musee_partenaire_ota');
        $this->addSql('DROP TABLE musee_pass_annuel');
        $this->addSql('DROP TABLE musee_politique_delestage');
        $this->addSql('DROP TABLE musee_qualification_langue_guide');
        $this->addSql('DROP TABLE musee_reservation_ota');
        $this->addSql('DROP TABLE musee_reversement');
        $this->addSql('DROP TABLE musee_salle');
        $this->addSql('DROP TABLE musee_sous_quota_salle');
        $this->addSql('DROP TABLE musee_visite_guidee');
    }
}
