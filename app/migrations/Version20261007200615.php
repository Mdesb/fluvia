<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tentatives de règlement (07/10/2026, ticket opposable, lot 2 — M-b du plan).
 *
 * Une tentative est écrite et validée AVANT que l'argent bouge. Deux index uniques font tout le
 * mécanisme : `uniq_payment_attempt_key` (une tentative par clé : jamais deux sollicitations du
 * terminal ou du porte-monnaie pour une même clé) et `uniq_payment_attempt_open_sale` (la vente,
 * tant que la tentative est en cours ou sans issue connue, nul ensuite : une seule à la fois).
 *
 * Table neuve et vide : aucune donnée réécrite, aucune fabriquée. Les colonnes de déclaration
 * (`declared_by_id`, `declared_at`, `card_reference`) servent au lot 3 ; elles sont posées ici pour
 * que la table s'écrive une fois, dans sa forme finale.
 *
 * SQL demandé à Doctrine (`doctrine:schema:update --dump-sql`) puis recopié, uuid en BINARY(16).
 */
final class Version20261007200615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Règlement : tentatives, une seule en cours par vente';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sale_payment_attempt (id BINARY(16) NOT NULL, idempotency_key BINARY(16) NOT NULL, payment_method_code VARCHAR(32) NOT NULL, requested_amount NUMERIC(10, 2) DEFAULT NULL, amount NUMERIC(10, 2) NOT NULL, uses_terminal TINYINT NOT NULL, status VARCHAR(24) NOT NULL, terminal_status VARCHAR(12) DEFAULT NULL, failure_reason VARCHAR(255) DEFAULT NULL, started_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, declared_at DATETIME DEFAULT NULL, card_reference VARCHAR(64) DEFAULT NULL, sale_id BINARY(16) NOT NULL, open_sale_id BINARY(16) DEFAULT NULL, payment_id BINARY(16) DEFAULT NULL, declared_by_id BINARY(16) DEFAULT NULL, INDEX IDX_3F6C3DF64A7E4868 (sale_id), INDEX IDX_3F6C3DF64C3A3BB (payment_id), INDEX IDX_3F6C3DF6C48B85B0 (declared_by_id), UNIQUE INDEX uniq_payment_attempt_key (idempotency_key), UNIQUE INDEX uniq_payment_attempt_open_sale (open_sale_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sale_payment_attempt ADD CONSTRAINT FK_3F6C3DF64A7E4868 FOREIGN KEY (sale_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE sale_payment_attempt ADD CONSTRAINT FK_3F6C3DF6274A849B FOREIGN KEY (open_sale_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE sale_payment_attempt ADD CONSTRAINT FK_3F6C3DF64C3A3BB FOREIGN KEY (payment_id) REFERENCES vente_paiement (id)');
        $this->addSql('ALTER TABLE sale_payment_attempt ADD CONSTRAINT FK_3F6C3DF6C48B85B0 FOREIGN KEY (declared_by_id) REFERENCES sec_utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        // Le retour arrière efface les tentatives : les règlements restent, seule la trace des
        // demandes (et la garde « en cours ») disparaît. Rien hors de ce lot ne lit cette table.
        $this->addSql('DROP TABLE sale_payment_attempt');
    }
}
