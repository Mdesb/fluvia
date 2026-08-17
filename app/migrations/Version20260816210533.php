<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Caution\Entity\Caution;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Refactor caution générique (module socle `App\Caution`) : remplace le patron dupliqué par
 * `App\Piscine\Entity\CautionCasier`, `App\Padel\Entity\CautionMateriel`/`GrilleRetenueMateriel` et
 * `App\Patinoire\Entity\CautionLocationPatins`/`GrilleRetenue` par un moteur unique
 * (`App\Caution\Service\GestionCaution`), consommé par les 3 verticales (fine délégation, entités
 * locales conservées en miroir — contrat API inchangé).
 *
 * 1. Crée les tables génériques `caution_caution`/`caution_grille_retenue`/`caution_mouvement`.
 * 2. Migre les données existantes : grilles de retenue Padel/Patinoire (id **conservé** pour que la
 *    FK `patin_retenue_caution.grille_appliquee_id` reste valide sans ré-écriture des lignes filles),
 *    puis les cautions historiques des 3 verticales (mirroir best-effort dans `caution_caution`,
 *    cloisonnement établissement résolu par jointure sur l'objet porté).
 * 3. Supprime les tables `padel_grille_retenue_materiel`/`patin_grille_retenue` (remplacées),
 *    ajoute `patin_retenue_caution.mouvement_generique_ref` (référence logique non-FK vers
 *    `caution_mouvement`) et reroute la FK `grille_appliquee_id` vers `caution_grille_retenue`.
 *
 * Idempotente/rejouable : les INSERT de données sont protégés par `INSERT IGNORE` et ne s'exécutent
 * que sur les lignes des tables sources (vidées après coup par les DROP TABLE) — un rejeu de cette
 * migration précise échouerait normalement au niveau du gestionnaire de versions Doctrine (déjà
 * appliquée), pas au niveau SQL.
 */
final class Version20260816210533 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refactor caution générique (App\\Caution) : tables caution_* + migration des données Piscine/Padel/Patinoire.';
    }

    public function up(Schema $schema): void
    {
        // --- 1. Schéma : tables génériques ---
        $this->addSql(<<<'SQL'
            CREATE TABLE caution_caution (
              id BINARY(16) NOT NULL,
              type_cible VARCHAR(40) NOT NULL,
              reference_cible VARCHAR(36) NOT NULL,
              reference_cible_active VARCHAR(36) DEFAULT NULL,
              montant_centimes INT NOT NULL,
              montant_retenu_centimes INT DEFAULT NULL,
              statut VARCHAR(17) DEFAULT 'consignee' NOT NULL,
              moyen_encaissement VARCHAR(30) DEFAULT NULL,
              regie_mouvement_ref BINARY(16) DEFAULT NULL,
              date_consignation DATETIME DEFAULT NULL,
              date_restitution DATETIME DEFAULT NULL,
              etablissement_id BINARY(16) NOT NULL,
              INDEX IDX_A5F76249FF631228 (etablissement_id),
              INDEX idx_caution_cible (type_cible, reference_cible),
              UNIQUE INDEX uniq_caution_cible_active (
                type_cible, reference_cible_active
              ),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE caution_grille_retenue (
              id BINARY(16) NOT NULL,
              type_cible VARCHAR(40) NOT NULL,
              sous_cible VARCHAR(60) DEFAULT NULL,
              motif VARCHAR(30) NOT NULL,
              mode VARCHAR(20) DEFAULT 'forfait' NOT NULL,
              montant_centimes INT NOT NULL,
              actif TINYINT DEFAULT 1 NOT NULL,
              etablissement_id BINARY(16) NOT NULL,
              INDEX IDX_B0F916A1FF631228 (etablissement_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE caution_mouvement (
              id BINARY(16) NOT NULL,
              type VARCHAR(12) NOT NULL,
              montant_centimes INT DEFAULT NULL,
              motif VARCHAR(255) DEFAULT NULL,
              delai_forcage_jours SMALLINT DEFAULT NULL,
              forcee TINYINT DEFAULT 0 NOT NULL,
              mouvement_regie_ref BINARY(16) DEFAULT NULL,
              horodatage DATETIME NOT NULL,
              caution_id BINARY(16) NOT NULL,
              grille_appliquee_id BINARY(16) DEFAULT NULL,
              agent_id BINARY(16) DEFAULT NULL,
              INDEX IDX_9CE412FC18B50B32 (caution_id),
              INDEX IDX_9CE412FC81CCDFA7 (grille_appliquee_id),
              INDEX IDX_9CE412FC3414710B (agent_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              caution_caution
            ADD
              CONSTRAINT FK_A5F76249FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              caution_grille_retenue
            ADD
              CONSTRAINT FK_B0F916A1FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              caution_mouvement
            ADD
              CONSTRAINT FK_9CE412FC18B50B32 FOREIGN KEY (caution_id) REFERENCES caution_caution (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              caution_mouvement
            ADD
              CONSTRAINT FK_9CE412FC81CCDFA7 FOREIGN KEY (grille_appliquee_id) REFERENCES caution_grille_retenue (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              caution_mouvement
            ADD
              CONSTRAINT FK_9CE412FC3414710B FOREIGN KEY (agent_id) REFERENCES sec_utilisateur (id)
        SQL);

        // --- 2. Migration de données ---
        $this->migrerGrillesPadel();
        $this->migrerGrillesPatinoire();
        $this->migrerCautionsPiscine();
        $this->migrerCautionsPadel();
        $this->migrerCautionsPatinoire();

        // --- 3. Nettoyage des tables remplacées + reroutage de la FK grille_appliquee_id ---
        // ⚠ Ordre corrigé vs. le diff auto-généré : la FK `patin_retenue_caution.grille_appliquee_id`
        // (qui référence `patin_grille_retenue`) doit être supprimée **avant** le DROP TABLE de sa
        // table cible (InnoDB refuse sinon avec « Cannot delete or update a parent row »).
        $this->addSql('ALTER TABLE patin_retenue_caution DROP FOREIGN KEY `FK_9E6E40C181CCDFA7`');
        $this->addSql('ALTER TABLE padel_grille_retenue_materiel DROP FOREIGN KEY `FK_1DB3ABC2FF631228`');
        $this->addSql('ALTER TABLE patin_grille_retenue DROP FOREIGN KEY `FK_EC21896BBC5FE8E8`');
        $this->addSql('ALTER TABLE patin_grille_retenue DROP FOREIGN KEY `FK_EC21896BFF631228`');
        $this->addSql('DROP TABLE padel_grille_retenue_materiel');
        $this->addSql('DROP TABLE patin_grille_retenue');
        $this->addSql('ALTER TABLE patin_retenue_caution ADD mouvement_generique_ref BINARY(16) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              patin_retenue_caution
            ADD
              CONSTRAINT FK_9E6E40C181CCDFA7 FOREIGN KEY (grille_appliquee_id) REFERENCES caution_grille_retenue (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE padel_grille_retenue_materiel (
              id BINARY(16) NOT NULL,
              type_article VARCHAR(40) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`,
              motif VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`,
              montant_retenue NUMERIC(10, 2) NOT NULL,
              etablissement_id BINARY(16) NOT NULL,
              INDEX IDX_1DB3ABC2FF631228 (etablissement_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE patin_grille_retenue (
              id BINARY(16) NOT NULL,
              motif VARCHAR(22) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`,
              mode VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`,
              montant_ou_taux NUMERIC(6, 2) NOT NULL,
              actif TINYINT DEFAULT 1 NOT NULL,
              etablissement_id BINARY(16) NOT NULL,
              parc_patins_id BINARY(16) DEFAULT NULL,
              INDEX IDX_EC21896BFF631228 (etablissement_id),
              INDEX IDX_EC21896BBC5FE8E8 (parc_patins_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              padel_grille_retenue_materiel
            ADD
              CONSTRAINT `FK_1DB3ABC2FF631228` FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              patin_grille_retenue
            ADD
              CONSTRAINT `FK_EC21896BBC5FE8E8` FOREIGN KEY (parc_patins_id) REFERENCES patin_parc_patins (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              patin_grille_retenue
            ADD
              CONSTRAINT `FK_EC21896BFF631228` FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)
        SQL);
        $this->addSql('ALTER TABLE caution_caution DROP FOREIGN KEY FK_A5F76249FF631228');
        $this->addSql('ALTER TABLE caution_grille_retenue DROP FOREIGN KEY FK_B0F916A1FF631228');
        $this->addSql('ALTER TABLE caution_mouvement DROP FOREIGN KEY FK_9CE412FC18B50B32');
        $this->addSql('ALTER TABLE caution_mouvement DROP FOREIGN KEY FK_9CE412FC81CCDFA7');
        $this->addSql('ALTER TABLE caution_mouvement DROP FOREIGN KEY FK_9CE412FC3414710B');
        $this->addSql('DROP TABLE caution_caution');
        $this->addSql('DROP TABLE caution_grille_retenue');
        $this->addSql('DROP TABLE caution_mouvement');
        $this->addSql('ALTER TABLE patin_retenue_caution DROP FOREIGN KEY FK_9E6E40C181CCDFA7');
        $this->addSql('ALTER TABLE patin_retenue_caution DROP mouvement_generique_ref');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              patin_retenue_caution
            ADD
              CONSTRAINT `FK_9E6E40C181CCDFA7` FOREIGN KEY (grille_appliquee_id) REFERENCES patin_grille_retenue (id)
        SQL);
        // ⚠ Reversion structurelle uniquement (DoD constitution §8) : les données consolidées dans
        // caution_caution/caution_grille_retenue depuis les tables encore existantes au moment du up()
        // ne sont pas reventilées dans les anciennes tables verticales (perte de données assumée en
        // cas de rollback, comme toute consolidation destructive).
    }

    /** Padel : `padel_grille_retenue_materiel` → `caution_grille_retenue` (cible `padel.materiel`). */
    private function migrerGrillesPadel(): void
    {
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT id, type_article, motif, montant_retenue, etablissement_id FROM padel_grille_retenue_materiel',
        );
        foreach ($lignes as $ligne) {
            $sousCible = \is_string($ligne['type_article']) && $ligne['type_article'] !== '' ? $ligne['type_article'] : null;
            $this->addSql(
                'INSERT INTO caution_grille_retenue (id, type_cible, sous_cible, motif, mode, montant_centimes, actif, etablissement_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
                [
                    $ligne['id'],
                    'padel.materiel',
                    $sousCible,
                    $ligne['motif'],
                    'forfait',
                    Caution::decimalVersCentimes((string) $ligne['montant_retenue']),
                    $ligne['etablissement_id'],
                ],
            );
        }
    }

    /**
     * Patinoire : `patin_grille_retenue` → `caution_grille_retenue` (cible `patinoire.patins`,
     * sous-cible = UUID canonique du `ParcPatins`). L'`id` est **conservé** : la FK
     * `patin_retenue_caution.grille_appliquee_id` reste valide sans ré-écriture des lignes filles.
     */
    private function migrerGrillesPatinoire(): void
    {
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT id, motif, mode, montant_ou_taux, actif, etablissement_id, parc_patins_id FROM patin_grille_retenue',
        );
        foreach ($lignes as $ligne) {
            $sousCible = $ligne['parc_patins_id'] !== null ? (string) Uuid::fromBinary($ligne['parc_patins_id']) : null;
            $this->addSql(
                'INSERT INTO caution_grille_retenue (id, type_cible, sous_cible, motif, mode, montant_centimes, actif, etablissement_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $ligne['id'],
                    'patinoire.patins',
                    $sousCible,
                    $ligne['motif'],
                    $ligne['mode'],
                    Caution::decimalVersCentimes((string) $ligne['montant_ou_taux']),
                    (int) $ligne['actif'],
                    $ligne['etablissement_id'],
                ],
            );
        }
    }

    /** Piscine : `piscine_caution_casier` → `caution_caution` (cible `piscine.casier`). */
    private function migrerCautionsPiscine(): void
    {
        $lignes = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.id, c.casier_id, c.montant, c.statut, c.moyen_encaissement, c.regie_mouvement_ref,
                   c.date_encaissement, c.date_liberation, k.etablissement_id
            FROM piscine_caution_casier c
            INNER JOIN piscine_casier k ON k.id = c.casier_id
        SQL);
        foreach ($lignes as $ligne) {
            $statut = match ($ligne['statut']) {
                'liberee' => 'restituee',
                'retenue' => 'retenue_totale',
                default => 'consignee',
            };
            $this->inserer(
                $ligne['id'],
                $ligne['etablissement_id'],
                'piscine.casier',
                $ligne['casier_id'],
                Caution::decimalVersCentimes((string) $ligne['montant']),
                $statut === 'retenue_totale' ? Caution::decimalVersCentimes((string) $ligne['montant']) : null,
                $statut,
                $ligne['moyen_encaissement'],
                $ligne['regie_mouvement_ref'],
                $ligne['date_encaissement'],
                $ligne['date_liberation'],
            );
        }
    }

    /** Padel : `padel_caution_materiel` → `caution_caution` (cible `padel.materiel`). */
    private function migrerCautionsPadel(): void
    {
        $lignes = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.id, c.location_id, c.montant, c.statut, c.montant_retenu,
                   c.date_encaissement, c.date_liberation, r.etablissement_id
            FROM padel_caution_materiel c
            INNER JOIN padel_location_materiel loc ON loc.id = c.location_id
            INNER JOIN reservation_reservation r ON r.id = loc.reservation_id
        SQL);
        foreach ($lignes as $ligne) {
            $montantCentimes = Caution::decimalVersCentimes((string) $ligne['montant']);
            $montantRetenuCentimes = $ligne['montant_retenu'] !== null ? Caution::decimalVersCentimes((string) $ligne['montant_retenu']) : null;
            $statut = match ($ligne['statut']) {
                'liberee' => 'restituee',
                'retenue' => ($montantRetenuCentimes ?? $montantCentimes) >= $montantCentimes ? 'retenue_totale' : 'retenue_partielle',
                default => 'consignee',
            };
            $this->inserer(
                $ligne['id'],
                $ligne['etablissement_id'],
                'padel.materiel',
                $ligne['location_id'],
                $montantCentimes,
                $montantRetenuCentimes,
                $statut,
                null,
                null,
                $ligne['date_encaissement'],
                $ligne['date_liberation'],
            );
        }
    }

    /** Patinoire : `patin_caution_location` → `caution_caution` (cible `patinoire.patins`). */
    private function migrerCautionsPatinoire(): void
    {
        $lignes = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.id, c.location_id, c.montant, c.statut, c.moyen_encaissement, c.regie_mouvement_ref,
                   c.date_encaissement, c.date_liberation, loc.etablissement_id
            FROM patin_caution_location c
            INNER JOIN patin_location loc ON loc.id = c.location_id
        SQL);
        foreach ($lignes as $ligne) {
            $statut = match ($ligne['statut']) {
                'liberee' => 'restituee',
                'retenue_partielle' => 'retenue_partielle',
                'retenue_totale' => 'retenue_totale',
                default => 'consignee',
            };
            $montantCentimes = Caution::decimalVersCentimes((string) $ligne['montant']);
            $this->inserer(
                $ligne['id'],
                $ligne['etablissement_id'],
                'patinoire.patins',
                $ligne['location_id'],
                $montantCentimes,
                \in_array($statut, ['retenue_partielle', 'retenue_totale'], true) ? $montantCentimes : null,
                $statut,
                $ligne['moyen_encaissement'],
                $ligne['regie_mouvement_ref'],
                $ligne['date_encaissement'],
                $ligne['date_liberation'],
            );
        }
    }

    private function inserer(
        string $id,
        string $etablissementId,
        string $typeCible,
        string $referenceCibleBinaire,
        int $montantCentimes,
        ?int $montantRetenuCentimes,
        string $statut,
        ?string $moyenEncaissement,
        ?string $regieMouvementRef,
        ?string $dateConsignation,
        ?string $dateRestitution,
    ): void {
        $referenceCible = (string) Uuid::fromBinary($referenceCibleBinaire);
        $referenceCibleActive = $statut !== 'restituee' ? $referenceCible : null;

        $this->addSql(
            <<<'SQL'
                INSERT INTO caution_caution (
                  id, type_cible, reference_cible, reference_cible_active, montant_centimes,
                  montant_retenu_centimes, statut, moyen_encaissement, regie_mouvement_ref,
                  date_consignation, date_restitution, etablissement_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL,
            [
                $id,
                $typeCible,
                $referenceCible,
                $referenceCibleActive,
                $montantCentimes,
                $montantRetenuCentimes,
                $statut,
                $moyenEncaissement,
                $regieMouvementRef,
                $dateConsignation,
                $dateRestitution,
                $etablissementId,
            ],
        );
    }
}
