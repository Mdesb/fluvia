<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #342 / #343 — clôtures mensuelle et annuelle scellées, archive fiscale annuelle.
 *
 * Écrite à la main (jamais un diff brut), horodatée après la dernière migration de `main`. DDL relevé
 * par `SHOW CREATE TABLE` sur les tables que Doctrine crée depuis le mapping, noms d'index compris.
 *
 * - `nf525_period_closure` : une clôture par point de vente, type (mensuelle/annuelle) et début de
 *   période. L'index unique empêche de compter deux fois un mois dans le cumul d'exercice, même sous
 *   deux requêtes concurrentes.
 * - `nf525_fiscal_archive` + `nf525_fiscal_archive_file` : l'archive (empreinte, signature, totaux) et
 *   son fichier ZIP, dans une table à part pour que lister les archives ne charge pas les fichiers,
 *   et en morceaux de 4 Mio parce que `max_allowed_packet` vaut 16 Mio (préprod, 05/11/2026).
 * - la permission `vente.fiscal_closing` (clore un mois, une année, archiver), semée ici et non en
 *   fixture : la migration est le seul chemin qui atteint la base d'un client. Rattachée à aucun
 *   rôle : seul l'administrateur la détient par le joker `vente.*`, la déléguer est une décision
 *   explicite.
 *
 * ⚠ RENOMMÉE À L'INTÉGRATION (08/11/2026, UTC) : elle s'appelait Version20261105130000, le même nom que
 *   la migration du logo de facture (#357), fusionnée avant elle. Doctrine identifie une migration par
 *   son nom de classe : sur une base où le logo est déjà joué, celle-ci aurait été tenue pour jouée et
 *   ses trois tables n'auraient jamais été créées.
 *
 * Aucune reprise : les tables naissent vides. Une clôture est un geste daté et signé ; en fabriquer
 * pour le passé écrirait des faits qui n'ont pas eu lieu.
 */
final class Version20261108160000 extends AbstractMigration
{
    private const PERMISSIONS = ['fiscal_closing'];

    public function getDescription(): string
    {
        return '#342/#343 : clôtures mensuelle/annuelle (nf525_period_closure) et archive fiscale annuelle.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE nf525_period_closure (
                id BINARY(16) NOT NULL,
                closure_type VARCHAR(12) NOT NULL,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                daily_closure_count INT DEFAULT 0 NOT NULL,
                sales_count INT DEFAULT 0 NOT NULL,
                period_total NUMERIC(14, 2) DEFAULT '0.00' NOT NULL,
                fiscal_year_total NUMERIC(14, 2) DEFAULT '0.00' NOT NULL,
                previous_grand_total NUMERIC(14, 2) DEFAULT '0.00' NOT NULL,
                grand_total NUMERIC(14, 2) DEFAULT '0.00' NOT NULL,
                recomputed_total NUMERIC(14, 2) DEFAULT '0.00' NOT NULL,
                last_sequence BIGINT DEFAULT NULL,
                closed_at DATETIME NOT NULL,
                point_de_vente_id BINARY(16) NOT NULL,
                author_id BINARY(16) DEFAULT NULL,
                etablissement_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_period_closure_pdv_type_start (point_de_vente_id, closure_type, period_start),
                INDEX IDX_86ADE1433F95E273 (point_de_vente_id),
                INDEX IDX_86ADE143F675F31B (author_id),
                INDEX IDX_86ADE143FF631228 (etablissement_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE nf525_period_closure ADD CONSTRAINT FK_86ADE1433F95E273 FOREIGN KEY (point_de_vente_id) REFERENCES caisse_point_de_vente (id)');
        $this->addSql('ALTER TABLE nf525_period_closure ADD CONSTRAINT FK_86ADE143F675F31B FOREIGN KEY (author_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE nf525_period_closure ADD CONSTRAINT FK_86ADE143FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE nf525_fiscal_archive (
                id BINARY(16) NOT NULL,
                fiscal_year INT NOT NULL,
                file_name VARCHAR(120) NOT NULL,
                file_size INT DEFAULT 0 NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                signature VARCHAR(64) NOT NULL,
                sales_count INT DEFAULT 0 NOT NULL,
                sales_total NUMERIC(14, 2) DEFAULT '0.00' NOT NULL,
                attendance_count INT DEFAULT 0 NOT NULL,
                summary JSON NOT NULL,
                created_at DATETIME NOT NULL,
                etablissement_id BINARY(16) NOT NULL,
                author_id BINARY(16) DEFAULT NULL,
                UNIQUE INDEX uniq_fiscal_archive_etab_year (etablissement_id, fiscal_year),
                INDEX IDX_C387796FFF631228 (etablissement_id),
                INDEX IDX_C387796FF675F31B (author_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE nf525_fiscal_archive ADD CONSTRAINT FK_C387796FF675F31B FOREIGN KEY (author_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE nf525_fiscal_archive ADD CONSTRAINT FK_C387796FFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');

        // Le fichier, en morceaux de 4 Mio : `max_allowed_packet` vaut 16 Mio sur MariaDB.
        $this->addSql(<<<'SQL'
            CREATE TABLE nf525_fiscal_archive_file (
                id BINARY(16) NOT NULL,
                part INT NOT NULL,
                content LONGBLOB NOT NULL,
                archive_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_fiscal_archive_file_part (archive_id, part),
                INDEX IDX_D842C0092956195F (archive_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE nf525_fiscal_archive_file ADD CONSTRAINT FK_D842C0092956195F FOREIGN KEY (archive_id) REFERENCES nf525_fiscal_archive (id)');

        foreach (self::PERMISSIONS as $action) {
            $this->addSql(
                <<<'SQL'
                    INSERT INTO sec_permission (id, module, action)
                    SELECT UNHEX(REPLACE(UUID(), '-', '')), 'vente', ? FROM DUAL
                    WHERE NOT EXISTS (SELECT 1 FROM sec_permission WHERE module = 'vente' AND action = ?)
                    SQL,
                [$action, $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Lu tout de suite (pas en `addSql`, qui est différé) : les tables existent encore à cet instant.
        // Une base qui porte des clôtures ou des archives scellées ne descend pas : les détruire
        // effacerait des pièces fiscales à conserver six ans.
        $scelles = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM nf525_period_closure')
            + (int) $this->connection->fetchOne('SELECT COUNT(*) FROM nf525_fiscal_archive');
        $this->abortIf($scelles > 0, sprintf(
            '%d clôture(s) de période ou archive(s) fiscale(s) existent : descendre les détruirait. Refusé.',
            $scelles,
        ));

        foreach (self::PERMISSIONS as $action) {
            // D'abord les rattachements : une permission encore liée à un rôle laisserait une ligne orpheline.
            $this->addSql(
                'DELETE rp FROM sec_role_permission rp JOIN sec_permission p ON p.id = rp.permission_id WHERE p.module = ? AND p.action = ?',
                ['vente', $action],
            );
            $this->addSql('DELETE FROM sec_permission WHERE module = ? AND action = ?', ['vente', $action]);
        }

        $this->addSql('DROP TABLE nf525_fiscal_archive_file');
        $this->addSql('DROP TABLE nf525_fiscal_archive');
        $this->addSql('DROP TABLE nf525_period_closure');
    }
}
