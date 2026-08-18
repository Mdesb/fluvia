<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module Facturation (`plan-facturation.md` §4, migration 1 — schéma) : tables
 * `facturation_destinataire`, `facturation_facture`, `facturation_ligne`, `facturation_parametre`,
 * `facturation_reglement`, `facturation_serie_numerotation` + FK vers `vente_vente`,
 * `compta_profil_exploitant`, `compta_periode_comptable`, `org_etablissement`,
 * `compta_ecriture_comptable`, `compta_ligne_ecriture`, `compta_facture_b2g`, `compta_compte_comptable`,
 * `compta_taux_tva`, `sec_utilisateur` (toutes réutilisées, non modifiées). Seed des 8 permissions
 * `facturation.*` (idempotent, même patron que `Version20260817192240`). Réversible (`down` symétrique).
 *
 * Champ isolé (ne touche aucune table hors `App\Facturation` ni aucun autre module) : générée par
 * `doctrine:migrations:diff` puis restreinte manuellement aux seules instructions `facturation_*`
 * (le diff brut incluait aussi le schéma en attente d'autres modules non liés à ce lot).
 */
final class Version20260818080740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Facturation : Facture/LigneFacture/DestinataireFacturation/SerieNumerotation/ReglementFacture/ParametreFacturationEtablissement, permissions facturation.*.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE facturation_destinataire (id BINARY(16) NOT NULL, type VARCHAR(16) DEFAULT \'particulier\' NOT NULL, nom VARCHAR(120) DEFAULT NULL, prenom VARCHAR(120) DEFAULT NULL, raison_sociale VARCHAR(180) DEFAULT NULL, siret VARCHAR(14) DEFAULT NULL, tva_intracommunautaire VARCHAR(20) DEFAULT NULL, adresse JSON NOT NULL, client_ref BINARY(16) DEFAULT NULL, est_organisme_public TINYINT DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE facturation_facture (id BINARY(16) NOT NULL, numero VARCHAR(32) DEFAULT NULL, nature VARCHAR(8) DEFAULT \'facture\' NOT NULL, origine VARCHAR(16) DEFAULT \'vente_a_terme\' NOT NULL, statut VARCHAR(24) DEFAULT \'brouillon\' NOT NULL, date_emission DATETIME DEFAULT NULL, date_echeance DATE DEFAULT NULL, conditions_reglement LONGTEXT DEFAULT NULL, total_ht NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, total_tva NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, total_ttc NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, ventilation_tva JSON DEFAULT NULL, mention_acquittee TINYINT DEFAULT 0 NOT NULL, acquittee_le DATETIME DEFAULT NULL, acquittee_moyen VARCHAR(120) DEFAULT NULL, acquittee_reference VARCHAR(64) DEFAULT NULL, canal VARCHAR(12) DEFAULT NULL, numero_sequence BIGINT DEFAULT 0 NOT NULL, empreinte VARCHAR(128) DEFAULT \'\' NOT NULL, empreinte_precedente VARCHAR(128) DEFAULT NULL, signature VARCHAR(512) DEFAULT \'\' NOT NULL, cree_le DATETIME NOT NULL, vente_origine_id BINARY(16) DEFAULT NULL, facture_corrigee_id BINARY(16) DEFAULT NULL, profil_exploitant_id BINARY(16) NOT NULL, periode_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, destinataire_id BINARY(16) NOT NULL, ecriture_generee_id BINARY(16) DEFAULT NULL, ligne_ecriture_client_id BINARY(16) DEFAULT NULL, facture_b2_g_id BINARY(16) DEFAULT NULL, cree_par_id BINARY(16) NOT NULL, INDEX IDX_B52288087A0F6D9A (facture_corrigee_id), INDEX IDX_B522880853ABECD4 (profil_exploitant_id), INDEX IDX_B5228808F384C1CF (periode_id), INDEX IDX_B5228808FF631228 (etablissement_id), UNIQUE INDEX UNIQ_B5228808A4F84F6E (destinataire_id), INDEX IDX_B5228808EE2CA29 (ecriture_generee_id), INDEX IDX_B522880897434D3E (ligne_ecriture_client_id), INDEX IDX_B52288085A20ECF (facture_b2_g_id), INDEX IDX_B5228808FC29C013 (cree_par_id), INDEX idx_facture_chaine (profil_exploitant_id, nature, numero_sequence), UNIQUE INDEX uniq_facture_numero (numero), UNIQUE INDEX uniq_facture_vente_origine (vente_origine_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE facturation_ligne (id BINARY(16) NOT NULL, designation VARCHAR(255) NOT NULL, ligne_vente_origine BINARY(16) DEFAULT NULL, categorie_comptable BINARY(16) DEFAULT NULL, quantite INT DEFAULT 1 NOT NULL, prix_unitaire_ht NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, montant_ht NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, montant_tva NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, montant_ttc NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, facture_id BINARY(16) NOT NULL, taux_tva_id BINARY(16) NOT NULL, INDEX IDX_654D86637F2DEE08 (facture_id), INDEX IDX_654D8663F7FEBCCE (taux_tva_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE facturation_parametre (id BINARY(16) NOT NULL, mentions_legales_emetteur JSON NOT NULL, conditions_reglement_defaut LONGTEXT NOT NULL, delai_paiement_defaut_jours INT DEFAULT 30 NOT NULL, taux_penalite_retard NUMERIC(5, 2) DEFAULT NULL, indemnite_forfaitaire_recouvrement NUMERIC(10, 2) DEFAULT \'40.00\' NOT NULL, mention_tva_specifique VARCHAR(255) DEFAULT NULL, chorus_pro_actif TINYINT DEFAULT 0 NOT NULL, profil_exploitant_id BINARY(16) NOT NULL, compte_produit_defaut_id BINARY(16) DEFAULT NULL, INDEX IDX_8760EDEF2900B8EF (compte_produit_defaut_id), UNIQUE INDEX uniq_facturation_parametre_profil (profil_exploitant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE facturation_reglement (id BINARY(16) NOT NULL, montant NUMERIC(10, 2) NOT NULL, moyen VARCHAR(32) NOT NULL, reference VARCHAR(64) DEFAULT NULL, date_reglement DATETIME NOT NULL, facture_id BINARY(16) NOT NULL, auteur_id BINARY(16) DEFAULT NULL, INDEX IDX_C043BCE27F2DEE08 (facture_id), INDEX IDX_C043BCE260BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE facturation_serie_numerotation (id BINARY(16) NOT NULL, prefixe VARCHAR(4) NOT NULL, dernier_numero INT DEFAULT 0 NOT NULL, profil_exploitant_id BINARY(16) NOT NULL, periode_id BINARY(16) NOT NULL, INDEX IDX_4D41722D53ABECD4 (profil_exploitant_id), INDEX IDX_4D41722DF384C1CF (periode_id), UNIQUE INDEX uniq_facturation_serie (profil_exploitant_id, periode_id, prefixe), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE facturation_facture ADD CONSTRAINT FK_B522880894446579 FOREIGN KEY (vente_origine_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE facturation_facture ADD CONSTRAINT FK_B52288087A0F6D9A FOREIGN KEY (facture_corrigee_id) REFERENCES facturation_facture (id)');
        $this->addSql('ALTER TABLE facturation_facture ADD CONSTRAINT FK_B522880853ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE facturation_facture ADD CONSTRAINT FK_B5228808F384C1CF FOREIGN KEY (periode_id) REFERENCES compta_periode_comptable (id)');
        $this->addSql('ALTER TABLE facturation_facture ADD CONSTRAINT FK_B5228808FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE facturation_facture ADD CONSTRAINT FK_B5228808A4F84F6E FOREIGN KEY (destinataire_id) REFERENCES facturation_destinataire (id)');
        $this->addSql('ALTER TABLE facturation_facture ADD CONSTRAINT FK_B5228808EE2CA29 FOREIGN KEY (ecriture_generee_id) REFERENCES compta_ecriture_comptable (id)');
        $this->addSql('ALTER TABLE facturation_facture ADD CONSTRAINT FK_B522880897434D3E FOREIGN KEY (ligne_ecriture_client_id) REFERENCES compta_ligne_ecriture (id)');
        $this->addSql('ALTER TABLE facturation_facture ADD CONSTRAINT FK_B52288085A20ECF FOREIGN KEY (facture_b2_g_id) REFERENCES compta_facture_b2g (id)');
        $this->addSql('ALTER TABLE facturation_facture ADD CONSTRAINT FK_B5228808FC29C013 FOREIGN KEY (cree_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE facturation_ligne ADD CONSTRAINT FK_654D86637F2DEE08 FOREIGN KEY (facture_id) REFERENCES facturation_facture (id)');
        $this->addSql('ALTER TABLE facturation_ligne ADD CONSTRAINT FK_654D8663F7FEBCCE FOREIGN KEY (taux_tva_id) REFERENCES compta_taux_tva (id)');
        $this->addSql('ALTER TABLE facturation_parametre ADD CONSTRAINT FK_8760EDEF53ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE facturation_parametre ADD CONSTRAINT FK_8760EDEF2900B8EF FOREIGN KEY (compte_produit_defaut_id) REFERENCES compta_compte_comptable (id)');
        $this->addSql('ALTER TABLE facturation_reglement ADD CONSTRAINT FK_C043BCE27F2DEE08 FOREIGN KEY (facture_id) REFERENCES facturation_facture (id)');
        $this->addSql('ALTER TABLE facturation_reglement ADD CONSTRAINT FK_C043BCE260BB6FE6 FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE facturation_serie_numerotation ADD CONSTRAINT FK_4D41722D53ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE facturation_serie_numerotation ADD CONSTRAINT FK_4D41722DF384C1CF FOREIGN KEY (periode_id) REFERENCES compta_periode_comptable (id)');

        // Permissions module `facturation` (§3/§4 du plan) — idempotentes, même patron que
        // Version20260817192240 (module acces-terminal).
        foreach (['lire', 'lire_soi', 'emettre_justificative', 'emettre_directe', 'avoir', 'lettrer', 'deposer_chorus', 'gerer'] as $action) {
            $this->addSql('INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)', [Uuid::v4()->toBinary(), 'facturation', $action]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'facturation'");

        $this->addSql('ALTER TABLE facturation_facture DROP FOREIGN KEY FK_B522880894446579');
        $this->addSql('ALTER TABLE facturation_facture DROP FOREIGN KEY FK_B52288087A0F6D9A');
        $this->addSql('ALTER TABLE facturation_facture DROP FOREIGN KEY FK_B522880853ABECD4');
        $this->addSql('ALTER TABLE facturation_facture DROP FOREIGN KEY FK_B5228808F384C1CF');
        $this->addSql('ALTER TABLE facturation_facture DROP FOREIGN KEY FK_B5228808FF631228');
        $this->addSql('ALTER TABLE facturation_facture DROP FOREIGN KEY FK_B5228808A4F84F6E');
        $this->addSql('ALTER TABLE facturation_facture DROP FOREIGN KEY FK_B5228808EE2CA29');
        $this->addSql('ALTER TABLE facturation_facture DROP FOREIGN KEY FK_B522880897434D3E');
        $this->addSql('ALTER TABLE facturation_facture DROP FOREIGN KEY FK_B52288085A20ECF');
        $this->addSql('ALTER TABLE facturation_facture DROP FOREIGN KEY FK_B5228808FC29C013');
        $this->addSql('ALTER TABLE facturation_ligne DROP FOREIGN KEY FK_654D86637F2DEE08');
        $this->addSql('ALTER TABLE facturation_ligne DROP FOREIGN KEY FK_654D8663F7FEBCCE');
        $this->addSql('ALTER TABLE facturation_parametre DROP FOREIGN KEY FK_8760EDEF53ABECD4');
        $this->addSql('ALTER TABLE facturation_parametre DROP FOREIGN KEY FK_8760EDEF2900B8EF');
        $this->addSql('ALTER TABLE facturation_reglement DROP FOREIGN KEY FK_C043BCE27F2DEE08');
        $this->addSql('ALTER TABLE facturation_reglement DROP FOREIGN KEY FK_C043BCE260BB6FE6');
        $this->addSql('ALTER TABLE facturation_serie_numerotation DROP FOREIGN KEY FK_4D41722D53ABECD4');
        $this->addSql('ALTER TABLE facturation_serie_numerotation DROP FOREIGN KEY FK_4D41722DF384C1CF');

        $this->addSql('DROP TABLE facturation_destinataire');
        $this->addSql('DROP TABLE facturation_facture');
        $this->addSql('DROP TABLE facturation_ligne');
        $this->addSql('DROP TABLE facturation_parametre');
        $this->addSql('DROP TABLE facturation_reglement');
        $this->addSql('DROP TABLE facturation_serie_numerotation');
    }
}
