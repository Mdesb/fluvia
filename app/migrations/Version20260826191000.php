<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PAY-2 — les dettes nées d'un refus de carte.
 *
 * Écrite à la main (D32). L'unicité (mandat, référence d'origine) est ce qui rend la bascule
 * idempotente : un événement redélivré met à jour la dette au lieu de faire payer deux fois.
 */
final class Version20260826191000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PAY-2 : table des dettes de bascule carte vers prélèvement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sepa_card_fallback_debt ('
            .'id BINARY(16) NOT NULL, '
            .'mandate_id BINARY(16) NOT NULL, '
            .'origin_reference VARCHAR(64) NOT NULL, '
            .'amount_cents INT NOT NULL, '
            .'due_date DATE NOT NULL, '
            .'invoice_id BINARY(16) DEFAULT NULL, '
            .'created_at DATETIME NOT NULL, '
            .'collected_at DATETIME DEFAULT NULL, '
            .'UNIQUE INDEX uniq_card_fallback_mandate_origin (mandate_id, origin_reference), '
            .'INDEX idx_card_fallback_due (due_date, collected_at), '
            .'PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE sepa_card_fallback_debt '
            .'ADD CONSTRAINT fk_card_fallback_mandate FOREIGN KEY (mandate_id) REFERENCES sepa_mandat (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sepa_card_fallback_debt DROP FOREIGN KEY fk_card_fallback_mandate');
        $this->addSql('DROP TABLE sepa_card_fallback_debt');
    }
}
