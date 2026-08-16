<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260816010609 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verticale Padel (App\\Padel) : Terrain/Parametrage/PlageHoraire/GrilleTarifaireTerrain, ReservationPadel (overlay), NiveauJoueur/HistoriqueNiveauJoueur, Tournoi/Poule/InscriptionTournoi/MatchTournoi, LocationMateriel/CautionMateriel/GrilleRetenueMateriel, RelaisEclairageTerrain/EvenementEclairage.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE padel_caution_materiel (id BINARY(16) NOT NULL, location_active BINARY(16) DEFAULT NULL, montant NUMERIC(10, 2) NOT NULL, statut VARCHAR(9) DEFAULT \'encaissee\' NOT NULL, montant_retenu NUMERIC(10, 2) DEFAULT NULL, date_encaissement DATETIME DEFAULT NULL, date_liberation DATETIME DEFAULT NULL, location_id BINARY(16) NOT NULL, INDEX IDX_6A9B5E6964D218E (location_id), UNIQUE INDEX uniq_caution_materiel_active (location_active), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_evenement_eclairage (id BINARY(16) NOT NULL, action VARCHAR(10) NOT NULL, horodatage DATETIME NOT NULL, statut VARCHAR(20) NOT NULL, motif VARCHAR(255) DEFAULT NULL, terrain_id BINARY(16) NOT NULL, reservation_id BINARY(16) DEFAULT NULL, operateur_id BINARY(16) DEFAULT NULL, INDEX IDX_4E488D638A2D8B41 (terrain_id), INDEX IDX_4E488D63B83297E7 (reservation_id), INDEX IDX_4E488D633F192FC (operateur_id), INDEX idx_evenement_eclairage_terrain_horodatage (terrain_id, horodatage), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_grille_retenue_materiel (id BINARY(16) NOT NULL, type_article VARCHAR(40) NOT NULL, motif VARCHAR(20) NOT NULL, montant_retenue NUMERIC(10, 2) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_1DB3ABC2FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_grille_tarifaire_terrain (id BINARY(16) NOT NULL, statut_joueur VARCHAR(11) NOT NULL, duree_minutes SMALLINT NOT NULL, prix NUMERIC(10, 2) NOT NULL, terrain_id BINARY(16) NOT NULL, plage_horaire_id BINARY(16) NOT NULL, INDEX IDX_45287DAC8A2D8B41 (terrain_id), INDEX IDX_45287DACB6BCB98B (plage_horaire_id), UNIQUE INDEX uniq_grille_terrain_plage_statut_duree (terrain_id, plage_horaire_id, statut_joueur, duree_minutes), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_historique_niveau (id BINARY(16) NOT NULL, ancienne_valeur SMALLINT NOT NULL, nouvelle_valeur SMALLINT NOT NULL, horodatage DATETIME NOT NULL, niveau_joueur_id BINARY(16) NOT NULL, auteur_id BINARY(16) NOT NULL, INDEX IDX_F63C8E35FB33339A (niveau_joueur_id), INDEX IDX_F63C8E3560BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_inscription_tournoi (id BINARY(16) NOT NULL, statut_paiement VARCHAR(11) DEFAULT \'en_attente\' NOT NULL, tournoi_id BINARY(16) NOT NULL, joueur1_id BINARY(16) NOT NULL, joueur2_id BINARY(16) NOT NULL, poule_id BINARY(16) DEFAULT NULL, vente_rattachee_id BINARY(16) DEFAULT NULL, INDEX IDX_FB2A9779F607770A (tournoi_id), INDEX IDX_FB2A977992C1E237 (joueur1_id), INDEX IDX_FB2A977980744DD9 (joueur2_id), INDEX IDX_FB2A977926596FD8 (poule_id), INDEX IDX_FB2A97791977E20D (vente_rattachee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_location_materiel (id BINARY(16) NOT NULL, article BINARY(16) NOT NULL, quantite SMALLINT NOT NULL, statut_retour VARCHAR(9) DEFAULT \'en_cours\' NOT NULL, reservation_id BINARY(16) NOT NULL, vente_rattachee_id BINARY(16) DEFAULT NULL, INDEX IDX_BD264F71B83297E7 (reservation_id), INDEX IDX_BD264F711977E20D (vente_rattachee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_match_tournoi (id BINARY(16) NOT NULL, tour SMALLINT DEFAULT NULL, score VARCHAR(60) DEFAULT NULL, statut VARCHAR(8) DEFAULT \'a_jouer\' NOT NULL, tournoi_id BINARY(16) NOT NULL, poule_id BINARY(16) DEFAULT NULL, paire_a_id BINARY(16) NOT NULL, paire_b_id BINARY(16) NOT NULL, terrain_id BINARY(16) NOT NULL, reservation_blocage_id BINARY(16) NOT NULL, vainqueur_id BINARY(16) DEFAULT NULL, INDEX IDX_FF8365E6F607770A (tournoi_id), INDEX IDX_FF8365E626596FD8 (poule_id), INDEX IDX_FF8365E61268482C (paire_a_id), INDEX IDX_FF8365E6DDE7C2 (paire_b_id), INDEX IDX_FF8365E68A2D8B41 (terrain_id), INDEX IDX_FF8365E622B28FE1 (reservation_blocage_id), INDEX IDX_FF8365E6773C35EE (vainqueur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_niveau_joueur (id BINARY(16) NOT NULL, niveau SMALLINT NOT NULL, statut VARCHAR(8) DEFAULT \'propose\' NOT NULL, date_validation DATETIME DEFAULT NULL, joueur_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, valide_par_utilisateur_id BINARY(16) DEFAULT NULL, INDEX IDX_C9CFE90CA9E2D76C (joueur_id), INDEX IDX_C9CFE90CFF631228 (etablissement_id), INDEX IDX_C9CFE90C9E1BD977 (valide_par_utilisateur_id), UNIQUE INDEX uniq_niveau_joueur_etablissement (joueur_id, etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_parametrage_etablissement (id BINARY(16) NOT NULL, produit_terrain_ref BINARY(16) DEFAULT NULL, type_tarif_membre_ref BINARY(16) DEFAULT NULL, type_tarif_non_membre_ref BINARY(16) DEFAULT NULL, echelle_niveau_min SMALLINT DEFAULT 1 NOT NULL, echelle_niveau_max SMALLINT DEFAULT 10 NOT NULL, mode_repartition_surcout VARCHAR(20) DEFAULT \'equitable_presents\' NOT NULL, tolerance_entree_badge_minutes SMALLINT DEFAULT NULL, majoration_coach_montant NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, etablissement_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_86DD0E2CFF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_plage_horaire (id BINARY(16) NOT NULL, libelle VARCHAR(8) NOT NULL, heure_debut TIME NOT NULL, heure_fin TIME NOT NULL, jours_applicables JSON NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_16656961FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_poule (id BINARY(16) NOT NULL, libelle VARCHAR(40) NOT NULL, tournoi_id BINARY(16) NOT NULL, INDEX IDX_BFF772C8F607770A (tournoi_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_relais_eclairage (id BINARY(16) NOT NULL, identifiant_relais VARCHAR(80) NOT NULL, mode_repli VARCHAR(6) DEFAULT \'manuel\' NOT NULL, statut VARCHAR(12) DEFAULT \'operationnel\' NOT NULL, terrain_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_F6E931E38A2D8B41 (terrain_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_reservation (id BINARY(16) NOT NULL, avec_coach TINYINT DEFAULT 0 NOT NULL, ouverte TINYINT DEFAULT 0 NOT NULL, niveau_vise_min SMALLINT DEFAULT NULL, niveau_vise_max SMALLINT DEFAULT NULL, statut_partie VARCHAR(14) DEFAULT NULL, reservation_id BINARY(16) NOT NULL, terrain_id BINARY(16) NOT NULL, coach_ressource_id BINARY(16) DEFAULT NULL, reservation_coach_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_9A7BC7DB83297E7 (reservation_id), INDEX IDX_9A7BC7D8A2D8B41 (terrain_id), INDEX IDX_9A7BC7DD9CED36 (coach_ressource_id), INDEX IDX_9A7BC7DA1A5B485 (reservation_coach_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_terrain (id BINARY(16) NOT NULL, type VARCHAR(10) NOT NULL, durees_autorisees_minutes JSON NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, ressource_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_B2FF2FFAFC6CD52A (ressource_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE padel_tournoi (id BINARY(16) NOT NULL, nom VARCHAR(150) NOT NULL, format VARCHAR(8) NOT NULL, categorie VARCHAR(60) DEFAULT NULL, niveau_requis_min SMALLINT DEFAULT NULL, niveau_requis_max SMALLINT DEFAULT NULL, frais_inscription NUMERIC(10, 2) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, statut VARCHAR(20) DEFAULT \'ouvert_inscriptions\' NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_6226A594FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE padel_caution_materiel ADD CONSTRAINT FK_6A9B5E6964D218E FOREIGN KEY (location_id) REFERENCES padel_location_materiel (id)');
        $this->addSql('ALTER TABLE padel_evenement_eclairage ADD CONSTRAINT FK_4E488D638A2D8B41 FOREIGN KEY (terrain_id) REFERENCES padel_terrain (id)');
        $this->addSql('ALTER TABLE padel_evenement_eclairage ADD CONSTRAINT FK_4E488D63B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE padel_evenement_eclairage ADD CONSTRAINT FK_4E488D633F192FC FOREIGN KEY (operateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE padel_grille_retenue_materiel ADD CONSTRAINT FK_1DB3ABC2FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE padel_grille_tarifaire_terrain ADD CONSTRAINT FK_45287DAC8A2D8B41 FOREIGN KEY (terrain_id) REFERENCES padel_terrain (id)');
        $this->addSql('ALTER TABLE padel_grille_tarifaire_terrain ADD CONSTRAINT FK_45287DACB6BCB98B FOREIGN KEY (plage_horaire_id) REFERENCES padel_plage_horaire (id)');
        $this->addSql('ALTER TABLE padel_historique_niveau ADD CONSTRAINT FK_F63C8E35FB33339A FOREIGN KEY (niveau_joueur_id) REFERENCES padel_niveau_joueur (id)');
        $this->addSql('ALTER TABLE padel_historique_niveau ADD CONSTRAINT FK_F63C8E3560BB6FE6 FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE padel_inscription_tournoi ADD CONSTRAINT FK_FB2A9779F607770A FOREIGN KEY (tournoi_id) REFERENCES padel_tournoi (id)');
        $this->addSql('ALTER TABLE padel_inscription_tournoi ADD CONSTRAINT FK_FB2A977992C1E237 FOREIGN KEY (joueur1_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE padel_inscription_tournoi ADD CONSTRAINT FK_FB2A977980744DD9 FOREIGN KEY (joueur2_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE padel_inscription_tournoi ADD CONSTRAINT FK_FB2A977926596FD8 FOREIGN KEY (poule_id) REFERENCES padel_poule (id)');
        $this->addSql('ALTER TABLE padel_inscription_tournoi ADD CONSTRAINT FK_FB2A97791977E20D FOREIGN KEY (vente_rattachee_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE padel_location_materiel ADD CONSTRAINT FK_BD264F71B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE padel_location_materiel ADD CONSTRAINT FK_BD264F711977E20D FOREIGN KEY (vente_rattachee_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE padel_match_tournoi ADD CONSTRAINT FK_FF8365E6F607770A FOREIGN KEY (tournoi_id) REFERENCES padel_tournoi (id)');
        $this->addSql('ALTER TABLE padel_match_tournoi ADD CONSTRAINT FK_FF8365E626596FD8 FOREIGN KEY (poule_id) REFERENCES padel_poule (id)');
        $this->addSql('ALTER TABLE padel_match_tournoi ADD CONSTRAINT FK_FF8365E61268482C FOREIGN KEY (paire_a_id) REFERENCES padel_inscription_tournoi (id)');
        $this->addSql('ALTER TABLE padel_match_tournoi ADD CONSTRAINT FK_FF8365E6DDE7C2 FOREIGN KEY (paire_b_id) REFERENCES padel_inscription_tournoi (id)');
        $this->addSql('ALTER TABLE padel_match_tournoi ADD CONSTRAINT FK_FF8365E68A2D8B41 FOREIGN KEY (terrain_id) REFERENCES padel_terrain (id)');
        $this->addSql('ALTER TABLE padel_match_tournoi ADD CONSTRAINT FK_FF8365E622B28FE1 FOREIGN KEY (reservation_blocage_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE padel_match_tournoi ADD CONSTRAINT FK_FF8365E6773C35EE FOREIGN KEY (vainqueur_id) REFERENCES padel_inscription_tournoi (id)');
        $this->addSql('ALTER TABLE padel_niveau_joueur ADD CONSTRAINT FK_C9CFE90CA9E2D76C FOREIGN KEY (joueur_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE padel_niveau_joueur ADD CONSTRAINT FK_C9CFE90CFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE padel_niveau_joueur ADD CONSTRAINT FK_C9CFE90C9E1BD977 FOREIGN KEY (valide_par_utilisateur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE padel_parametrage_etablissement ADD CONSTRAINT FK_86DD0E2CFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE padel_plage_horaire ADD CONSTRAINT FK_16656961FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE padel_poule ADD CONSTRAINT FK_BFF772C8F607770A FOREIGN KEY (tournoi_id) REFERENCES padel_tournoi (id)');
        $this->addSql('ALTER TABLE padel_relais_eclairage ADD CONSTRAINT FK_F6E931E38A2D8B41 FOREIGN KEY (terrain_id) REFERENCES padel_terrain (id)');
        $this->addSql('ALTER TABLE padel_reservation ADD CONSTRAINT FK_9A7BC7DB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE padel_reservation ADD CONSTRAINT FK_9A7BC7D8A2D8B41 FOREIGN KEY (terrain_id) REFERENCES padel_terrain (id)');
        $this->addSql('ALTER TABLE padel_reservation ADD CONSTRAINT FK_9A7BC7DD9CED36 FOREIGN KEY (coach_ressource_id) REFERENCES reservation_ressource (id)');
        $this->addSql('ALTER TABLE padel_reservation ADD CONSTRAINT FK_9A7BC7DA1A5B485 FOREIGN KEY (reservation_coach_id) REFERENCES reservation_reservation (id)');
        $this->addSql('ALTER TABLE padel_terrain ADD CONSTRAINT FK_B2FF2FFAFC6CD52A FOREIGN KEY (ressource_id) REFERENCES reservation_ressource (id)');
        $this->addSql('ALTER TABLE padel_tournoi ADD CONSTRAINT FK_6226A594FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE padel_caution_materiel DROP FOREIGN KEY FK_6A9B5E6964D218E');
        $this->addSql('ALTER TABLE padel_evenement_eclairage DROP FOREIGN KEY FK_4E488D638A2D8B41');
        $this->addSql('ALTER TABLE padel_evenement_eclairage DROP FOREIGN KEY FK_4E488D63B83297E7');
        $this->addSql('ALTER TABLE padel_evenement_eclairage DROP FOREIGN KEY FK_4E488D633F192FC');
        $this->addSql('ALTER TABLE padel_grille_retenue_materiel DROP FOREIGN KEY FK_1DB3ABC2FF631228');
        $this->addSql('ALTER TABLE padel_grille_tarifaire_terrain DROP FOREIGN KEY FK_45287DAC8A2D8B41');
        $this->addSql('ALTER TABLE padel_grille_tarifaire_terrain DROP FOREIGN KEY FK_45287DACB6BCB98B');
        $this->addSql('ALTER TABLE padel_historique_niveau DROP FOREIGN KEY FK_F63C8E35FB33339A');
        $this->addSql('ALTER TABLE padel_historique_niveau DROP FOREIGN KEY FK_F63C8E3560BB6FE6');
        $this->addSql('ALTER TABLE padel_inscription_tournoi DROP FOREIGN KEY FK_FB2A9779F607770A');
        $this->addSql('ALTER TABLE padel_inscription_tournoi DROP FOREIGN KEY FK_FB2A977992C1E237');
        $this->addSql('ALTER TABLE padel_inscription_tournoi DROP FOREIGN KEY FK_FB2A977980744DD9');
        $this->addSql('ALTER TABLE padel_inscription_tournoi DROP FOREIGN KEY FK_FB2A977926596FD8');
        $this->addSql('ALTER TABLE padel_inscription_tournoi DROP FOREIGN KEY FK_FB2A97791977E20D');
        $this->addSql('ALTER TABLE padel_location_materiel DROP FOREIGN KEY FK_BD264F71B83297E7');
        $this->addSql('ALTER TABLE padel_location_materiel DROP FOREIGN KEY FK_BD264F711977E20D');
        $this->addSql('ALTER TABLE padel_match_tournoi DROP FOREIGN KEY FK_FF8365E6F607770A');
        $this->addSql('ALTER TABLE padel_match_tournoi DROP FOREIGN KEY FK_FF8365E626596FD8');
        $this->addSql('ALTER TABLE padel_match_tournoi DROP FOREIGN KEY FK_FF8365E61268482C');
        $this->addSql('ALTER TABLE padel_match_tournoi DROP FOREIGN KEY FK_FF8365E6DDE7C2');
        $this->addSql('ALTER TABLE padel_match_tournoi DROP FOREIGN KEY FK_FF8365E68A2D8B41');
        $this->addSql('ALTER TABLE padel_match_tournoi DROP FOREIGN KEY FK_FF8365E622B28FE1');
        $this->addSql('ALTER TABLE padel_match_tournoi DROP FOREIGN KEY FK_FF8365E6773C35EE');
        $this->addSql('ALTER TABLE padel_niveau_joueur DROP FOREIGN KEY FK_C9CFE90CA9E2D76C');
        $this->addSql('ALTER TABLE padel_niveau_joueur DROP FOREIGN KEY FK_C9CFE90CFF631228');
        $this->addSql('ALTER TABLE padel_niveau_joueur DROP FOREIGN KEY FK_C9CFE90C9E1BD977');
        $this->addSql('ALTER TABLE padel_parametrage_etablissement DROP FOREIGN KEY FK_86DD0E2CFF631228');
        $this->addSql('ALTER TABLE padel_plage_horaire DROP FOREIGN KEY FK_16656961FF631228');
        $this->addSql('ALTER TABLE padel_poule DROP FOREIGN KEY FK_BFF772C8F607770A');
        $this->addSql('ALTER TABLE padel_relais_eclairage DROP FOREIGN KEY FK_F6E931E38A2D8B41');
        $this->addSql('ALTER TABLE padel_reservation DROP FOREIGN KEY FK_9A7BC7DB83297E7');
        $this->addSql('ALTER TABLE padel_reservation DROP FOREIGN KEY FK_9A7BC7D8A2D8B41');
        $this->addSql('ALTER TABLE padel_reservation DROP FOREIGN KEY FK_9A7BC7DD9CED36');
        $this->addSql('ALTER TABLE padel_reservation DROP FOREIGN KEY FK_9A7BC7DA1A5B485');
        $this->addSql('ALTER TABLE padel_terrain DROP FOREIGN KEY FK_B2FF2FFAFC6CD52A');
        $this->addSql('ALTER TABLE padel_tournoi DROP FOREIGN KEY FK_6226A594FF631228');
        $this->addSql('DROP TABLE padel_caution_materiel');
        $this->addSql('DROP TABLE padel_evenement_eclairage');
        $this->addSql('DROP TABLE padel_grille_retenue_materiel');
        $this->addSql('DROP TABLE padel_grille_tarifaire_terrain');
        $this->addSql('DROP TABLE padel_historique_niveau');
        $this->addSql('DROP TABLE padel_inscription_tournoi');
        $this->addSql('DROP TABLE padel_location_materiel');
        $this->addSql('DROP TABLE padel_match_tournoi');
        $this->addSql('DROP TABLE padel_niveau_joueur');
        $this->addSql('DROP TABLE padel_parametrage_etablissement');
        $this->addSql('DROP TABLE padel_plage_horaire');
        $this->addSql('DROP TABLE padel_poule');
        $this->addSql('DROP TABLE padel_relais_eclairage');
        $this->addSql('DROP TABLE padel_reservation');
        $this->addSql('DROP TABLE padel_terrain');
        $this->addSql('DROP TABLE padel_tournoi');
    }
}
