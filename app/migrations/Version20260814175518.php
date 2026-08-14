<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M2 Vente & Caisse (L2) — migration structurelle : tables caisse_* (point de vente, caisse,
 * session, mouvement, clôture Z), vente_* (vente, ligne, paiement, avoir, billet/support) et
 * nf525_operation_scellee. Contraintes : unicité numéro session/vente/avoir, clé d'idempotence,
 * unicité (pointDeVente, numeroSequence) de la chaîne NF525, unicité « une session active par point
 * de vente » (colonne pdv_actif), et CHECK métier (fonds/seuils ≥ 0, montants > 0, quantité ≥ 1,
 * fermeture ≥ ouverture). Suppose les migrations socle L0 + M1 jouées d'abord (FK etablissement /
 * utilisateur / off_stock / off_pool).
 */
final class Version20260814175518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M2 Vente & Caisse (L2) : schéma caisse_*, vente_*, nf525_operation_scellee + index/contraintes.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE caisse_caisse (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, etat VARCHAR(16) DEFAULT \'securisee\' NOT NULL, point_de_vente_id BINARY(16) NOT NULL, INDEX IDX_BE847CA93F95E273 (point_de_vente_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE caisse_cloture_z (id BINARY(16) NOT NULL, comptages JSON NOT NULL, total_ventes NUMERIC(10, 2) NOT NULL, total_remboursements NUMERIC(10, 2) NOT NULL, versement NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, fond_reporte NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, ecart_total NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, horodatage DATETIME NOT NULL, etat_de_regie JSON NOT NULL, type_cloture VARCHAR(12) DEFAULT \'Z\' NOT NULL, session_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_24069427613FECDF (session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE caisse_mouvement (id BINARY(16) NOT NULL, type VARCHAR(16) NOT NULL, montant NUMERIC(10, 2) NOT NULL, motif VARCHAR(255) NOT NULL, date_heure DATETIME NOT NULL, alerte_regisseur TINYINT DEFAULT 0 NOT NULL, session_id BINARY(16) NOT NULL, auteur_id BINARY(16) NOT NULL, INDEX IDX_A552D023613FECDF (session_id), INDEX IDX_A552D02360BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE caisse_point_de_vente (id BINARY(16) NOT NULL, libelle VARCHAR(120) NOT NULL, imprimante JSON DEFAULT NULL, tpe JSON DEFAULT NULL, favoris JSON DEFAULT NULL, seuil_impression NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, seuil_alerte_retrait NUMERIC(10, 2) DEFAULT NULL, moyens_autorises JSON NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_33F51EA4FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE caisse_session (id BINARY(16) NOT NULL, numero VARCHAR(32) NOT NULL, fond_de_caisse NUMERIC(10, 2) NOT NULL, etat VARCHAR(16) DEFAULT \'ouverte\' NOT NULL, ouverture_le DATETIME NOT NULL, fermeture_le DATETIME DEFAULT NULL, pdv_actif BINARY(16) DEFAULT NULL, point_de_vente_id BINARY(16) NOT NULL, caisse_id BINARY(16) NOT NULL, regisseur_id BINARY(16) NOT NULL, operateur_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_EAFDA3353F95E273 (point_de_vente_id), INDEX IDX_EAFDA33527B4FEBF (caisse_id), INDEX IDX_EAFDA3359FBE122E (regisseur_id), INDEX IDX_EAFDA3353F192FC (operateur_id), INDEX IDX_EAFDA335FF631228 (etablissement_id), UNIQUE INDEX uniq_session_numero (numero), UNIQUE INDEX uniq_session_active_pdv (pdv_actif), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE nf525_operation_scellee (id BINARY(16) NOT NULL, type_operation VARCHAR(24) NOT NULL, cible_type VARCHAR(64) NOT NULL, cible_id BINARY(16) NOT NULL, numero_sequence BIGINT NOT NULL, empreinte VARCHAR(128) NOT NULL, empreinte_precedente VARCHAR(128) DEFAULT NULL, signature VARCHAR(512) NOT NULL, payload_canonique JSON NOT NULL, horodatage DATETIME NOT NULL, point_de_vente_id BINARY(16) NOT NULL, INDEX IDX_40250F883F95E273 (point_de_vente_id), UNIQUE INDEX uniq_op_pdv_sequence (point_de_vente_id, numero_sequence), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vente_avoir (id BINARY(16) NOT NULL, numero VARCHAR(32) NOT NULL, montant NUMERIC(10, 2) NOT NULL, motif VARCHAR(255) NOT NULL, date_heure DATETIME NOT NULL, support_invalide TINYINT DEFAULT 0 NOT NULL, nature VARCHAR(16) DEFAULT \'annulation\' NOT NULL, vente_origine_id BINARY(16) NOT NULL, auteur_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_718A170A94446579 (vente_origine_id), INDEX IDX_718A170A60BB6FE6 (auteur_id), INDEX IDX_718A170AFF631228 (etablissement_id), UNIQUE INDEX uniq_avoir_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vente_billet_support (id BINARY(16) NOT NULL, type VARCHAR(12) DEFAULT \'billet\' NOT NULL, identifiant_support VARCHAR(128) DEFAULT NULL, statut_appairage VARCHAR(12) DEFAULT \'en_attente\' NOT NULL, nb_compostages INT DEFAULT NULL, vente_id BINARY(16) NOT NULL, ligne_id BINARY(16) DEFAULT NULL, INDEX IDX_F9CD38A57DC7170A (vente_id), INDEX IDX_F9CD38A55A438E76 (ligne_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vente_ligne (id BINARY(16) NOT NULL, produit BINARY(16) NOT NULL, type_tarif BINARY(16) NOT NULL, saison BINARY(16) DEFAULT NULL, quantite INT DEFAULT 1 NOT NULL, prix_unitaire NUMERIC(10, 2) NOT NULL, prix_force TINYINT DEFAULT 0 NOT NULL, beneficiaire BINARY(16) DEFAULT NULL, remise_ligne NUMERIC(10, 2) DEFAULT NULL, remise_type VARCHAR(12) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, promotions_appliquees JSON DEFAULT NULL, montant_ligne NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, vente_id BINARY(16) NOT NULL, INDEX IDX_43E1D6CA7DC7170A (vente_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vente_paiement (id BINARY(16) NOT NULL, moyen_code VARCHAR(32) NOT NULL, montant NUMERIC(10, 2) NOT NULL, rendu NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, ref_tpe VARCHAR(64) DEFAULT NULL, statut_tpe VARCHAR(12) DEFAULT NULL, banque VARCHAR(64) DEFAULT NULL, numero_cheque VARCHAR(64) DEFAULT NULL, differe TINYINT DEFAULT 0 NOT NULL, date_heure DATETIME NOT NULL, vente_id BINARY(16) NOT NULL, INDEX IDX_118992FA7DC7170A (vente_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vente_vente (id BINARY(16) NOT NULL, numero VARCHAR(32) NOT NULL, date DATETIME NOT NULL, client BINARY(16) DEFAULT NULL, statut VARCHAR(16) DEFAULT \'en_cours\' NOT NULL, total NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, total_remises NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, reste_apayer NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, origine_hors_ligne TINYINT DEFAULT 0 NOT NULL, cle_idempotence BINARY(16) NOT NULL, imprime TINYINT DEFAULT 0 NOT NULL, session_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_9C9B2705613FECDF (session_id), INDEX IDX_9C9B2705FF631228 (etablissement_id), UNIQUE INDEX uniq_vente_numero (numero), UNIQUE INDEX uniq_vente_cle_idempotence (cle_idempotence), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE caisse_caisse ADD CONSTRAINT FK_BE847CA93F95E273 FOREIGN KEY (point_de_vente_id) REFERENCES caisse_point_de_vente (id)');
        $this->addSql('ALTER TABLE caisse_cloture_z ADD CONSTRAINT FK_24069427613FECDF FOREIGN KEY (session_id) REFERENCES caisse_session (id)');
        $this->addSql('ALTER TABLE caisse_mouvement ADD CONSTRAINT FK_A552D023613FECDF FOREIGN KEY (session_id) REFERENCES caisse_session (id)');
        $this->addSql('ALTER TABLE caisse_mouvement ADD CONSTRAINT FK_A552D02360BB6FE6 FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE caisse_point_de_vente ADD CONSTRAINT FK_33F51EA4FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE caisse_session ADD CONSTRAINT FK_EAFDA3353F95E273 FOREIGN KEY (point_de_vente_id) REFERENCES caisse_point_de_vente (id)');
        $this->addSql('ALTER TABLE caisse_session ADD CONSTRAINT FK_EAFDA33527B4FEBF FOREIGN KEY (caisse_id) REFERENCES caisse_caisse (id)');
        $this->addSql('ALTER TABLE caisse_session ADD CONSTRAINT FK_EAFDA3359FBE122E FOREIGN KEY (regisseur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE caisse_session ADD CONSTRAINT FK_EAFDA3353F192FC FOREIGN KEY (operateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE caisse_session ADD CONSTRAINT FK_EAFDA335FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE nf525_operation_scellee ADD CONSTRAINT FK_40250F883F95E273 FOREIGN KEY (point_de_vente_id) REFERENCES caisse_point_de_vente (id)');
        $this->addSql('ALTER TABLE vente_avoir ADD CONSTRAINT FK_718A170A94446579 FOREIGN KEY (vente_origine_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE vente_avoir ADD CONSTRAINT FK_718A170A60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE vente_avoir ADD CONSTRAINT FK_718A170AFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE vente_billet_support ADD CONSTRAINT FK_F9CD38A57DC7170A FOREIGN KEY (vente_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE vente_billet_support ADD CONSTRAINT FK_F9CD38A55A438E76 FOREIGN KEY (ligne_id) REFERENCES vente_ligne (id)');
        $this->addSql('ALTER TABLE vente_ligne ADD CONSTRAINT FK_43E1D6CA7DC7170A FOREIGN KEY (vente_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE vente_paiement ADD CONSTRAINT FK_118992FA7DC7170A FOREIGN KEY (vente_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE vente_vente ADD CONSTRAINT FK_9C9B2705613FECDF FOREIGN KEY (session_id) REFERENCES caisse_session (id)');
        $this->addSql('ALTER TABLE vente_vente ADD CONSTRAINT FK_9C9B2705FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');

        // CHECK métier (plan §7). MariaDB 11.4 applique les contraintes CHECK.
        $this->addSql('ALTER TABLE caisse_point_de_vente ADD CONSTRAINT chk_pdv_seuil CHECK (seuil_impression >= 0)');
        $this->addSql('ALTER TABLE caisse_session ADD CONSTRAINT chk_session_fond CHECK (fond_de_caisse >= 0)');
        $this->addSql('ALTER TABLE caisse_session ADD CONSTRAINT chk_session_fermeture CHECK (fermeture_le IS NULL OR fermeture_le >= ouverture_le)');
        $this->addSql('ALTER TABLE caisse_mouvement ADD CONSTRAINT chk_mouvement_montant CHECK (montant > 0)');
        $this->addSql('ALTER TABLE vente_ligne ADD CONSTRAINT chk_ligne_quantite CHECK (quantite >= 1)');
        $this->addSql('ALTER TABLE vente_paiement ADD CONSTRAINT chk_paiement_montant CHECK (montant > 0)');
        $this->addSql('ALTER TABLE vente_paiement ADD CONSTRAINT chk_paiement_rendu CHECK (rendu >= 0)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE caisse_caisse DROP FOREIGN KEY FK_BE847CA93F95E273');
        $this->addSql('ALTER TABLE caisse_cloture_z DROP FOREIGN KEY FK_24069427613FECDF');
        $this->addSql('ALTER TABLE caisse_mouvement DROP FOREIGN KEY FK_A552D023613FECDF');
        $this->addSql('ALTER TABLE caisse_mouvement DROP FOREIGN KEY FK_A552D02360BB6FE6');
        $this->addSql('ALTER TABLE caisse_point_de_vente DROP FOREIGN KEY FK_33F51EA4FF631228');
        $this->addSql('ALTER TABLE caisse_session DROP FOREIGN KEY FK_EAFDA3353F95E273');
        $this->addSql('ALTER TABLE caisse_session DROP FOREIGN KEY FK_EAFDA33527B4FEBF');
        $this->addSql('ALTER TABLE caisse_session DROP FOREIGN KEY FK_EAFDA3359FBE122E');
        $this->addSql('ALTER TABLE caisse_session DROP FOREIGN KEY FK_EAFDA3353F192FC');
        $this->addSql('ALTER TABLE caisse_session DROP FOREIGN KEY FK_EAFDA335FF631228');
        $this->addSql('ALTER TABLE nf525_operation_scellee DROP FOREIGN KEY FK_40250F883F95E273');
        $this->addSql('ALTER TABLE vente_avoir DROP FOREIGN KEY FK_718A170A94446579');
        $this->addSql('ALTER TABLE vente_avoir DROP FOREIGN KEY FK_718A170A60BB6FE6');
        $this->addSql('ALTER TABLE vente_avoir DROP FOREIGN KEY FK_718A170AFF631228');
        $this->addSql('ALTER TABLE vente_billet_support DROP FOREIGN KEY FK_F9CD38A57DC7170A');
        $this->addSql('ALTER TABLE vente_billet_support DROP FOREIGN KEY FK_F9CD38A55A438E76');
        $this->addSql('ALTER TABLE vente_ligne DROP FOREIGN KEY FK_43E1D6CA7DC7170A');
        $this->addSql('ALTER TABLE vente_paiement DROP FOREIGN KEY FK_118992FA7DC7170A');
        $this->addSql('ALTER TABLE vente_vente DROP FOREIGN KEY FK_9C9B2705613FECDF');
        $this->addSql('ALTER TABLE vente_vente DROP FOREIGN KEY FK_9C9B2705FF631228');
        $this->addSql('DROP TABLE caisse_caisse');
        $this->addSql('DROP TABLE caisse_cloture_z');
        $this->addSql('DROP TABLE caisse_mouvement');
        $this->addSql('DROP TABLE caisse_point_de_vente');
        $this->addSql('DROP TABLE caisse_session');
        $this->addSql('DROP TABLE nf525_operation_scellee');
        $this->addSql('DROP TABLE vente_avoir');
        $this->addSql('DROP TABLE vente_billet_support');
        $this->addSql('DROP TABLE vente_ligne');
        $this->addSql('DROP TABLE vente_paiement');
        $this->addSql('DROP TABLE vente_vente');
    }
}
