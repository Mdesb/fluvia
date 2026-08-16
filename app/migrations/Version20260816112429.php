<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260816112429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module M3 Boutique en ligne (L8, plan-boutique.md §1/§5) : Vitrine, PanierEnLigne/'
            . 'LignePanierEnLigne, satellites SuiviCommandeEnLigne/LigneCommandeMeta/BilletQrMeta '
            . '(OneToOne Vente/LigneVente/BilletSupport M2, aucune duplication), CompteClient/'
            . 'SessionClient (auth client final), DemandeRemboursement, RetraitClickCollect, '
            . 'PartenaireOTA/AllocationQuotaOTA/ReversementOTA génériques L8.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bou_allocation_quota_ota (id BINARY(16) NOT NULL, quota_alloue INT NOT NULL, quota_consomme INT DEFAULT 0 NOT NULL, partenaire_id BINARY(16) NOT NULL, produit_id BINARY(16) DEFAULT NULL, creneau_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_65F5477298DE13AC (partenaire_id), INDEX IDX_65F54772F347EFB (produit_id), INDEX IDX_65F547727D0729A9 (creneau_id), INDEX IDX_65F54772FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_billet_qr_meta (id BINARY(16) NOT NULL, qr_dynamique VARCHAR(255) NOT NULL, pass_wallet_disponible TINYINT DEFAULT 0 NOT NULL, pass_wallet_url VARCHAR(255) DEFAULT NULL, repli_qr TINYINT DEFAULT 0 NOT NULL, validite_debut DATETIME DEFAULT NULL, validite_fin DATETIME DEFAULT NULL, statut_retrait_physique VARCHAR(14) DEFAULT NULL, billet_support_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_billet_qr_meta_support (billet_support_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_compte_client (id BINARY(16) NOT NULL, france_connect_id VARCHAR(255) DEFAULT NULL, utilisateur_id BINARY(16) NOT NULL, client_id BINARY(16) NOT NULL, vitrine_creation_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_8F70DC6519EB6921 (client_id), INDEX IDX_8F70DC651EE9FF52 (vitrine_creation_id), INDEX IDX_8F70DC65FF631228 (etablissement_id), UNIQUE INDEX uniq_compte_client_utilisateur (utilisateur_id), UNIQUE INDEX uniq_compte_client_franceconnect (france_connect_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_demande_remboursement (id BINARY(16) NOT NULL, motif LONGTEXT NOT NULL, pieces_justificatives JSON DEFAULT NULL, statut VARCHAR(10) DEFAULT \'recue\' NOT NULL, origine_automatique TINYINT DEFAULT 0 NOT NULL, date_demande DATETIME NOT NULL, date_traitement DATETIME DEFAULT NULL, motif_refus VARCHAR(255) DEFAULT NULL, vente_id BINARY(16) NOT NULL, ligne_id BINARY(16) DEFAULT NULL, traite_par_id BINARY(16) DEFAULT NULL, avoir_rattache_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_5AA716407DC7170A (vente_id), INDEX IDX_5AA716405A438E76 (ligne_id), INDEX IDX_5AA71640167FABE8 (traite_par_id), INDEX IDX_5AA716409304D95D (avoir_rattache_id), INDEX IDX_5AA71640FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_ligne_commande_meta (id BINARY(16) NOT NULL, champs_personnalises JSON DEFAULT NULL, beneficiaire_simple JSON DEFAULT NULL, ligne_vente_id BINARY(16) NOT NULL, creneau_id BINARY(16) DEFAULT NULL, INDEX IDX_67E8106B7D0729A9 (creneau_id), UNIQUE INDEX uniq_ligne_commande_meta_ligne (ligne_vente_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_ligne_panier (id BINARY(16) NOT NULL, quantite INT DEFAULT 1 NOT NULL, beneficiaire_simple JSON DEFAULT NULL, champs_personnalises JSON DEFAULT NULL, autorisation_parentale_requise TINYINT DEFAULT 0 NOT NULL, autorisation_parentale_horodatage DATETIME DEFAULT NULL, expiration_a DATETIME NOT NULL, panier_id BINARY(16) NOT NULL, produit_id BINARY(16) NOT NULL, creneau_id BINARY(16) DEFAULT NULL, beneficiaire_ref_id BINARY(16) DEFAULT NULL, INDEX IDX_7D190D78F77D927C (panier_id), INDEX IDX_7D190D78F347EFB (produit_id), INDEX IDX_7D190D787D0729A9 (creneau_id), INDEX IDX_7D190D784C4B58F7 (beneficiaire_ref_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_panier (id BINARY(16) NOT NULL, statut VARCHAR(24) DEFAULT \'ouvert\' NOT NULL, date_creation DATETIME NOT NULL, date_expiration DATETIME NOT NULL, contact_connu VARCHAR(180) DEFAULT NULL, relance_envoyee TINYINT DEFAULT 0 NOT NULL, consentement_rgpd_horodatage DATETIME DEFAULT NULL, client_resolu BINARY(16) DEFAULT NULL, vitrine_id BINARY(16) NOT NULL, compte_client_id BINARY(16) DEFAULT NULL, session_client_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_116158B527F17893 (vitrine_id), INDEX IDX_116158B5DA655713 (compte_client_id), INDEX IDX_116158B55A092C2B (session_client_id), INDEX IDX_116158B5FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_partenaire_ota (id BINARY(16) NOT NULL, nom VARCHAR(120) NOT NULL, tarif_net NUMERIC(10, 2) NOT NULL, commission NUMERIC(5, 2) NOT NULL, code_connecteur VARCHAR(60) DEFAULT NULL, actif TINYINT DEFAULT 1 NOT NULL, vitrine_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_E7F6D95227F17893 (vitrine_id), INDEX IDX_E7F6D952FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_retrait_click_collect (id BINARY(16) NOT NULL, code_retrait VARCHAR(12) NOT NULL, statut VARCHAR(10) DEFAULT \'a_retirer\' NOT NULL, date_retrait DATETIME DEFAULT NULL, billet_support_id BINARY(16) NOT NULL, point_retrait_id BINARY(16) DEFAULT NULL, traite_par_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_F7CD4FCFA28D66D0 (point_retrait_id), INDEX IDX_F7CD4FCF167FABE8 (traite_par_id), INDEX IDX_F7CD4FCFFF631228 (etablissement_id), UNIQUE INDEX uniq_retrait_billet_support (billet_support_id), UNIQUE INDEX uniq_retrait_code (code_retrait), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_reversement_ota (id BINARY(16) NOT NULL, periode_debut DATE NOT NULL, periode_fin DATE NOT NULL, montant NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, statut VARCHAR(10) DEFAULT \'a_verser\' NOT NULL, partenaire_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_AE62179998DE13AC (partenaire_id), INDEX IDX_AE621799FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_session_client (id BINARY(16) NOT NULL, token VARCHAR(64) NOT NULL, type VARCHAR(24) NOT NULL, contact_email VARCHAR(180) DEFAULT NULL, expiration DATETIME NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_36D9362EFF631228 (etablissement_id), UNIQUE INDEX uniq_session_client_token (token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_suivi_commande (id BINARY(16) NOT NULL, statut_tunnel VARCHAR(10) DEFAULT \'panier\' NOT NULL, origine_ota TINYINT DEFAULT 0 NOT NULL, vente_id BINARY(16) NOT NULL, vitrine_id BINARY(16) NOT NULL, panier_origine_id BINARY(16) NOT NULL, compte_client_id BINARY(16) DEFAULT NULL, partenaire_ota_id BINARY(16) DEFAULT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_56250ECC27F17893 (vitrine_id), INDEX IDX_56250ECC6452A01B (panier_origine_id), INDEX IDX_56250ECCDA655713 (compte_client_id), INDEX IDX_56250ECC75D56FB4 (partenaire_ota_id), INDEX IDX_56250ECCFF631228 (etablissement_id), UNIQUE INDEX uniq_suivi_commande_vente (vente_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bou_vitrine (id BINARY(16) NOT NULL, logo VARCHAR(255) DEFAULT NULL, couleurs JSON DEFAULT NULL, langues JSON NOT NULL, canaux_actifs JSON NOT NULL, delai_expiration_panier_minutes SMALLINT DEFAULT 15 NOT NULL, etablissement_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_vitrine_etablissement (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bou_allocation_quota_ota ADD CONSTRAINT FK_65F5477298DE13AC FOREIGN KEY (partenaire_id) REFERENCES bou_partenaire_ota (id)');
        $this->addSql('ALTER TABLE bou_allocation_quota_ota ADD CONSTRAINT FK_65F54772F347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id)');
        $this->addSql('ALTER TABLE bou_allocation_quota_ota ADD CONSTRAINT FK_65F547727D0729A9 FOREIGN KEY (creneau_id) REFERENCES reservation_creneau (id)');
        $this->addSql('ALTER TABLE bou_allocation_quota_ota ADD CONSTRAINT FK_65F54772FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE bou_billet_qr_meta ADD CONSTRAINT FK_8A89A74014A66179 FOREIGN KEY (billet_support_id) REFERENCES vente_billet_support (id)');
        $this->addSql('ALTER TABLE bou_compte_client ADD CONSTRAINT FK_8F70DC65FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE bou_compte_client ADD CONSTRAINT FK_8F70DC6519EB6921 FOREIGN KEY (client_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE bou_compte_client ADD CONSTRAINT FK_8F70DC651EE9FF52 FOREIGN KEY (vitrine_creation_id) REFERENCES bou_vitrine (id)');
        $this->addSql('ALTER TABLE bou_compte_client ADD CONSTRAINT FK_8F70DC65FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE bou_demande_remboursement ADD CONSTRAINT FK_5AA716407DC7170A FOREIGN KEY (vente_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE bou_demande_remboursement ADD CONSTRAINT FK_5AA716405A438E76 FOREIGN KEY (ligne_id) REFERENCES vente_ligne (id)');
        $this->addSql('ALTER TABLE bou_demande_remboursement ADD CONSTRAINT FK_5AA71640167FABE8 FOREIGN KEY (traite_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE bou_demande_remboursement ADD CONSTRAINT FK_5AA716409304D95D FOREIGN KEY (avoir_rattache_id) REFERENCES vente_avoir (id)');
        $this->addSql('ALTER TABLE bou_demande_remboursement ADD CONSTRAINT FK_5AA71640FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE bou_ligne_commande_meta ADD CONSTRAINT FK_67E8106B225D063C FOREIGN KEY (ligne_vente_id) REFERENCES vente_ligne (id)');
        $this->addSql('ALTER TABLE bou_ligne_commande_meta ADD CONSTRAINT FK_67E8106B7D0729A9 FOREIGN KEY (creneau_id) REFERENCES reservation_creneau (id)');
        $this->addSql('ALTER TABLE bou_ligne_panier ADD CONSTRAINT FK_7D190D78F77D927C FOREIGN KEY (panier_id) REFERENCES bou_panier (id)');
        $this->addSql('ALTER TABLE bou_ligne_panier ADD CONSTRAINT FK_7D190D78F347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id)');
        $this->addSql('ALTER TABLE bou_ligne_panier ADD CONSTRAINT FK_7D190D787D0729A9 FOREIGN KEY (creneau_id) REFERENCES reservation_creneau (id)');
        $this->addSql('ALTER TABLE bou_ligne_panier ADD CONSTRAINT FK_7D190D784C4B58F7 FOREIGN KEY (beneficiaire_ref_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE bou_panier ADD CONSTRAINT FK_116158B527F17893 FOREIGN KEY (vitrine_id) REFERENCES bou_vitrine (id)');
        $this->addSql('ALTER TABLE bou_panier ADD CONSTRAINT FK_116158B5DA655713 FOREIGN KEY (compte_client_id) REFERENCES bou_compte_client (id)');
        $this->addSql('ALTER TABLE bou_panier ADD CONSTRAINT FK_116158B55A092C2B FOREIGN KEY (session_client_id) REFERENCES bou_session_client (id)');
        $this->addSql('ALTER TABLE bou_panier ADD CONSTRAINT FK_116158B5FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE bou_partenaire_ota ADD CONSTRAINT FK_E7F6D95227F17893 FOREIGN KEY (vitrine_id) REFERENCES bou_vitrine (id)');
        $this->addSql('ALTER TABLE bou_partenaire_ota ADD CONSTRAINT FK_E7F6D952FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE bou_retrait_click_collect ADD CONSTRAINT FK_F7CD4FCF14A66179 FOREIGN KEY (billet_support_id) REFERENCES vente_billet_support (id)');
        $this->addSql('ALTER TABLE bou_retrait_click_collect ADD CONSTRAINT FK_F7CD4FCFA28D66D0 FOREIGN KEY (point_retrait_id) REFERENCES org_espace (id)');
        $this->addSql('ALTER TABLE bou_retrait_click_collect ADD CONSTRAINT FK_F7CD4FCF167FABE8 FOREIGN KEY (traite_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE bou_retrait_click_collect ADD CONSTRAINT FK_F7CD4FCFFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE bou_reversement_ota ADD CONSTRAINT FK_AE62179998DE13AC FOREIGN KEY (partenaire_id) REFERENCES bou_partenaire_ota (id)');
        $this->addSql('ALTER TABLE bou_reversement_ota ADD CONSTRAINT FK_AE621799FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE bou_session_client ADD CONSTRAINT FK_36D9362EFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE bou_suivi_commande ADD CONSTRAINT FK_56250ECC7DC7170A FOREIGN KEY (vente_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE bou_suivi_commande ADD CONSTRAINT FK_56250ECC27F17893 FOREIGN KEY (vitrine_id) REFERENCES bou_vitrine (id)');
        $this->addSql('ALTER TABLE bou_suivi_commande ADD CONSTRAINT FK_56250ECC6452A01B FOREIGN KEY (panier_origine_id) REFERENCES bou_panier (id)');
        $this->addSql('ALTER TABLE bou_suivi_commande ADD CONSTRAINT FK_56250ECCDA655713 FOREIGN KEY (compte_client_id) REFERENCES bou_compte_client (id)');
        $this->addSql('ALTER TABLE bou_suivi_commande ADD CONSTRAINT FK_56250ECC75D56FB4 FOREIGN KEY (partenaire_ota_id) REFERENCES bou_partenaire_ota (id)');
        $this->addSql('ALTER TABLE bou_suivi_commande ADD CONSTRAINT FK_56250ECCFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE bou_vitrine ADD CONSTRAINT FK_996610D2FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bou_allocation_quota_ota DROP FOREIGN KEY FK_65F5477298DE13AC');
        $this->addSql('ALTER TABLE bou_allocation_quota_ota DROP FOREIGN KEY FK_65F54772F347EFB');
        $this->addSql('ALTER TABLE bou_allocation_quota_ota DROP FOREIGN KEY FK_65F547727D0729A9');
        $this->addSql('ALTER TABLE bou_allocation_quota_ota DROP FOREIGN KEY FK_65F54772FF631228');
        $this->addSql('ALTER TABLE bou_billet_qr_meta DROP FOREIGN KEY FK_8A89A74014A66179');
        $this->addSql('ALTER TABLE bou_compte_client DROP FOREIGN KEY FK_8F70DC65FB88E14F');
        $this->addSql('ALTER TABLE bou_compte_client DROP FOREIGN KEY FK_8F70DC6519EB6921');
        $this->addSql('ALTER TABLE bou_compte_client DROP FOREIGN KEY FK_8F70DC651EE9FF52');
        $this->addSql('ALTER TABLE bou_compte_client DROP FOREIGN KEY FK_8F70DC65FF631228');
        $this->addSql('ALTER TABLE bou_demande_remboursement DROP FOREIGN KEY FK_5AA716407DC7170A');
        $this->addSql('ALTER TABLE bou_demande_remboursement DROP FOREIGN KEY FK_5AA716405A438E76');
        $this->addSql('ALTER TABLE bou_demande_remboursement DROP FOREIGN KEY FK_5AA71640167FABE8');
        $this->addSql('ALTER TABLE bou_demande_remboursement DROP FOREIGN KEY FK_5AA716409304D95D');
        $this->addSql('ALTER TABLE bou_demande_remboursement DROP FOREIGN KEY FK_5AA71640FF631228');
        $this->addSql('ALTER TABLE bou_ligne_commande_meta DROP FOREIGN KEY FK_67E8106B225D063C');
        $this->addSql('ALTER TABLE bou_ligne_commande_meta DROP FOREIGN KEY FK_67E8106B7D0729A9');
        $this->addSql('ALTER TABLE bou_ligne_panier DROP FOREIGN KEY FK_7D190D78F77D927C');
        $this->addSql('ALTER TABLE bou_ligne_panier DROP FOREIGN KEY FK_7D190D78F347EFB');
        $this->addSql('ALTER TABLE bou_ligne_panier DROP FOREIGN KEY FK_7D190D787D0729A9');
        $this->addSql('ALTER TABLE bou_ligne_panier DROP FOREIGN KEY FK_7D190D784C4B58F7');
        $this->addSql('ALTER TABLE bou_panier DROP FOREIGN KEY FK_116158B527F17893');
        $this->addSql('ALTER TABLE bou_panier DROP FOREIGN KEY FK_116158B5DA655713');
        $this->addSql('ALTER TABLE bou_panier DROP FOREIGN KEY FK_116158B55A092C2B');
        $this->addSql('ALTER TABLE bou_panier DROP FOREIGN KEY FK_116158B5FF631228');
        $this->addSql('ALTER TABLE bou_partenaire_ota DROP FOREIGN KEY FK_E7F6D95227F17893');
        $this->addSql('ALTER TABLE bou_partenaire_ota DROP FOREIGN KEY FK_E7F6D952FF631228');
        $this->addSql('ALTER TABLE bou_retrait_click_collect DROP FOREIGN KEY FK_F7CD4FCF14A66179');
        $this->addSql('ALTER TABLE bou_retrait_click_collect DROP FOREIGN KEY FK_F7CD4FCFA28D66D0');
        $this->addSql('ALTER TABLE bou_retrait_click_collect DROP FOREIGN KEY FK_F7CD4FCF167FABE8');
        $this->addSql('ALTER TABLE bou_retrait_click_collect DROP FOREIGN KEY FK_F7CD4FCFFF631228');
        $this->addSql('ALTER TABLE bou_reversement_ota DROP FOREIGN KEY FK_AE62179998DE13AC');
        $this->addSql('ALTER TABLE bou_reversement_ota DROP FOREIGN KEY FK_AE621799FF631228');
        $this->addSql('ALTER TABLE bou_session_client DROP FOREIGN KEY FK_36D9362EFF631228');
        $this->addSql('ALTER TABLE bou_suivi_commande DROP FOREIGN KEY FK_56250ECC7DC7170A');
        $this->addSql('ALTER TABLE bou_suivi_commande DROP FOREIGN KEY FK_56250ECC27F17893');
        $this->addSql('ALTER TABLE bou_suivi_commande DROP FOREIGN KEY FK_56250ECC6452A01B');
        $this->addSql('ALTER TABLE bou_suivi_commande DROP FOREIGN KEY FK_56250ECCDA655713');
        $this->addSql('ALTER TABLE bou_suivi_commande DROP FOREIGN KEY FK_56250ECC75D56FB4');
        $this->addSql('ALTER TABLE bou_suivi_commande DROP FOREIGN KEY FK_56250ECCFF631228');
        $this->addSql('ALTER TABLE bou_vitrine DROP FOREIGN KEY FK_996610D2FF631228');
        $this->addSql('DROP TABLE bou_allocation_quota_ota');
        $this->addSql('DROP TABLE bou_billet_qr_meta');
        $this->addSql('DROP TABLE bou_compte_client');
        $this->addSql('DROP TABLE bou_demande_remboursement');
        $this->addSql('DROP TABLE bou_ligne_commande_meta');
        $this->addSql('DROP TABLE bou_ligne_panier');
        $this->addSql('DROP TABLE bou_panier');
        $this->addSql('DROP TABLE bou_partenaire_ota');
        $this->addSql('DROP TABLE bou_retrait_click_collect');
        $this->addSql('DROP TABLE bou_reversement_ota');
        $this->addSql('DROP TABLE bou_session_client');
        $this->addSql('DROP TABLE bou_suivi_commande');
        $this->addSql('DROP TABLE bou_vitrine');
    }
}
