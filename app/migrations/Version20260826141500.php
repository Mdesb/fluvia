<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PAY-2 — le préavis de prélèvement SEPA.
 *
 * Écrite à la main (D32) : le diff proposé par Doctrine ratissait la dérive des autres sessions. Deux
 * instructions seulement, et elles sont les miennes.
 *
 * `prenotification_delay_days` arrive avec un défaut à 14, qui est la règle SEPA quand rien d'autre
 * n'a été convenu. Le défaut est porté par la colonne et non par le seul PHP : les configurations
 * créancier déjà en base doivent hériter d'un délai, sans quoi elles vaudraient zéro jour de préavis
 * après la migration — c'est-à-dire aucun.
 */
final class Version20260826141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PAY-2 : table des préavis de prélèvement SEPA et délai de préavis du créancier.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sepa_debit_prenotification ('
            .'id BINARY(16) NOT NULL, '
            .'mandate_id BINARY(16) NOT NULL, '
            .'origin_reference VARCHAR(64) NOT NULL, '
            .'amount_cents INT NOT NULL, '
            .'announced_due_date DATE NOT NULL, '
            .'sent_at DATETIME NOT NULL, '
            .'reason VARCHAR(20) NOT NULL, '
            .'outcome VARCHAR(20) NOT NULL, '
            .'UNIQUE INDEX uniq_prenotification_mandate_origin (mandate_id, origin_reference), '
            .'INDEX idx_prenotification_mandate (mandate_id), '
            .'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE sepa_debit_prenotification '
            .'ADD CONSTRAINT fk_prenotification_mandate FOREIGN KEY (mandate_id) REFERENCES sepa_mandat (id)');

        $this->addSql('ALTER TABLE sepa_config_creancier '
            .'ADD prenotification_delay_days INT DEFAULT 14 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sepa_debit_prenotification DROP FOREIGN KEY fk_prenotification_mandate');
        $this->addSql('DROP TABLE sepa_debit_prenotification');
        $this->addSql('ALTER TABLE sepa_config_creancier DROP prenotification_delay_days');
    }
}
