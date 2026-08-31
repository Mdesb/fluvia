<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ACT-3 (D16) — le séjour : `stay_stay` (un client, une période, un compte) et `stay_charge` (les
 * lignes de ce compte).
 *
 * ⚠ Migration écrite à la main, comme `Version20260822090000.php` et `Version20260824090000.php` :
 * `doctrine:migrations:diff` propose systématiquement des suppressions d'index sur ce dépôt (C14
 * ouverte). Relue ligne à ligne — deux `CREATE TABLE` neufs et leurs seules contraintes ; aucune table,
 * aucun index, aucune colonne d'un autre module n'est touché.
 *
 * **`UNIQ_STAY_CHARGE_SOURCE` porte l'idempotence du module** et n'est donc pas un index de confort :
 * le transport asynchrone (D7-bis) rejoue un message après échec, et sans cette contrainte une
 * consommation relivrée deux fois facturerait le client deux fois. La protection est au schéma pour
 * qu'elle survive à un bogue de listener.
 */
final class Version20260824181500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ACT-3 : tables du séjour (stay_stay, stay_charge) avec clé d\'idempotence sur la source.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stay_stay ('
            . 'id BINARY(16) NOT NULL, '
            . 'establishment_id BINARY(16) NOT NULL, '
            . 'customer_id BINARY(16) NOT NULL, '
            . 'reference VARCHAR(32) NOT NULL, '
            . 'arrival_date DATE NOT NULL, '
            . 'expected_departure_date DATE DEFAULT NULL, '
            . "status VARCHAR(16) DEFAULT 'open' NOT NULL, "
            . 'opened_at DATETIME NOT NULL, '
            . 'closed_at DATETIME DEFAULT NULL, '
            . 'settled_at DATETIME DEFAULT NULL, '
            . 'INDEX IDX_STAY_ETAB_STATUS (establishment_id, status), '
            . 'INDEX IDX_STAY_ETAB_ARRIVAL (establishment_id, arrival_date), '
            . 'INDEX IDX_STAY_CUSTOMER (customer_id), '
            . 'UNIQUE INDEX UNIQ_STAY_ETAB_REFERENCE (establishment_id, reference), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE stay_charge ('
            . 'id BINARY(16) NOT NULL, '
            . 'stay_id BINARY(16) NOT NULL, '
            . 'establishment_id BINARY(16) NOT NULL, '
            . 'label VARCHAR(255) NOT NULL, '
            . 'amount NUMERIC(10, 2) NOT NULL, '
            . 'occurred_at DATETIME NOT NULL, '
            . 'source_module VARCHAR(32) NOT NULL, '
            . 'source_event VARCHAR(64) NOT NULL, '
            . 'source_subject_id VARCHAR(64) NOT NULL, '
            . 'INDEX IDX_STAY_CHARGE_STAY (stay_id), '
            . 'INDEX IDX_STAY_CHARGE_ETAB_OCCURRED (establishment_id, occurred_at), '
            . 'UNIQUE INDEX UNIQ_STAY_CHARGE_SOURCE (stay_id, source_event, source_subject_id), '
            . 'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        // RESTRICT sur l'établissement et le client : un séjour encaissé ne doit pas disparaître avec
        // la fiche qui l'a produit — la trace comptable prime sur la commodité de suppression.
        $this->addSql('ALTER TABLE stay_stay ADD CONSTRAINT FK_STAY_ETAB FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE stay_stay ADD CONSTRAINT FK_STAY_CUSTOMER FOREIGN KEY (customer_id) REFERENCES crm_client (id)');

        // CASCADE depuis le séjour, en revanche : une ligne n'a aucun sens sans son compte.
        $this->addSql('ALTER TABLE stay_charge ADD CONSTRAINT FK_STAY_CHARGE_STAY FOREIGN KEY (stay_id) REFERENCES stay_stay (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stay_charge ADD CONSTRAINT FK_STAY_CHARGE_ETAB FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        // Ordre inverse : `stay_charge` référence `stay_stay`.
        $this->addSql('ALTER TABLE stay_charge DROP FOREIGN KEY FK_STAY_CHARGE_STAY');
        $this->addSql('ALTER TABLE stay_charge DROP FOREIGN KEY FK_STAY_CHARGE_ETAB');
        $this->addSql('ALTER TABLE stay_stay DROP FOREIGN KEY FK_STAY_ETAB');
        $this->addSql('ALTER TABLE stay_stay DROP FOREIGN KEY FK_STAY_CUSTOMER');
        $this->addSql('DROP TABLE stay_charge');
        $this->addSql('DROP TABLE stay_stay');
    }
}
