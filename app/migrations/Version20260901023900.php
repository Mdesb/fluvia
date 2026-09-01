<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ACT-4 — les tables de la restauration : `dining_order` (l addition d une table) et
 * `dining_order_line` (ce qui a ete commande).
 *
 * ⚠ Ecrite a la main et horodatee en **heure locale** (D32) : le conteneur PHP tourne en UTC, deux
 * heures derriere, et une migration horodatee la-bas se classerait avant des migrations deja
 * appliquees. Relue ligne a ligne : deux `CREATE TABLE` neufs et leurs seules contraintes, aucun
 * `DROP`, aucune table d un autre module touchee.
 *
 * **Les `DEFAULT` et les index de cle etrangere sont declares ici ET au mapping.** C est la lecon du
 * lot precedent : un `DEFAULT` pose en migration mais absent des attributs, ou un index de cle
 * etrangere laisse a Doctrine, ressort en `CHANGE` ou en `RENAME INDEX` dans le diff de **toutes** les
 * sessions — chacune croyant que la derive vient de son propre lot.
 */
final class Version20260901023900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restauration : tables dining_order et dining_order_line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dining_order ('
            . 'id BINARY(16) NOT NULL, '
            . 'establishment_id BINARY(16) NOT NULL, '
            . 'reference VARCHAR(32) NOT NULL, '
            . 'table_label VARCHAR(64) NOT NULL, '
            . 'covers INT DEFAULT 1 NOT NULL, '
            . "status VARCHAR(16) DEFAULT 'open' NOT NULL, "
            . 'opened_at DATETIME NOT NULL, '
            . 'closed_at DATETIME DEFAULT NULL, '
            . 'settled_at DATETIME DEFAULT NULL, '
            . 'INDEX IDX_DINING_ORDER_ETAB_STATUS (establishment_id, status), '
            . 'INDEX IDX_DINING_ORDER_ETAB_OPENED (establishment_id, opened_at), '
            . 'UNIQUE INDEX UNIQ_DINING_ORDER_ETAB_REFERENCE (establishment_id, reference), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE dining_order_line ('
            . 'id BINARY(16) NOT NULL, '
            . 'dining_order_id BINARY(16) NOT NULL, '
            . 'establishment_id BINARY(16) NOT NULL, '
            . 'course_code VARCHAR(40) NOT NULL, '
            . 'course_rank INT NOT NULL, '
            . 'label VARCHAR(255) NOT NULL, '
            . 'quantity INT NOT NULL, '
            . 'unit_amount NUMERIC(10, 2) NOT NULL, '
            . "status VARCHAR(16) DEFAULT 'draft' NOT NULL, "
            . 'fired_at DATETIME DEFAULT NULL, '
            . 'void_reason VARCHAR(255) DEFAULT NULL, '
            . 'INDEX IDX_DINING_LINE_ORDER (dining_order_id), '
            . 'INDEX IDX_DINING_LINE_ETAB_STATUS (establishment_id, status), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        // RESTRICT sur l etablissement : une addition encaissee ne doit pas disparaitre avec la fiche
        // qui l a produite — la trace comptable prime sur la commodite de suppression.
        $this->addSql('ALTER TABLE dining_order ADD CONSTRAINT FK_DINING_ORDER_ETAB FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE dining_order_line ADD CONSTRAINT FK_DINING_LINE_ETAB FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');

        // CASCADE depuis l addition : une ligne n a aucun sens sans la table qui l a commandee.
        $this->addSql('ALTER TABLE dining_order_line ADD CONSTRAINT FK_DINING_LINE_ORDER FOREIGN KEY (dining_order_id) REFERENCES dining_order (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Ordre inverse : les lignes referencent l addition.
        $this->addSql('ALTER TABLE dining_order_line DROP FOREIGN KEY FK_DINING_LINE_ORDER');
        $this->addSql('ALTER TABLE dining_order_line DROP FOREIGN KEY FK_DINING_LINE_ETAB');
        $this->addSql('ALTER TABLE dining_order DROP FOREIGN KEY FK_DINING_ORDER_ETAB');
        $this->addSql('DROP TABLE dining_order_line');
        $this->addSql('DROP TABLE dining_order');
    }
}
