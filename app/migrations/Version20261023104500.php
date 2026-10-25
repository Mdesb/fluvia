<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Terminal de paiement, lot 1 (23/10/2026) : la tentative porte son connecteur et ce que le terminal
 * a rendu — montant débité (paiement partiel), autorisation, transaction et identifiant du terminal,
 * réseau, tickets client et commerçant.
 *
 * Colonnes neuves et nulles : aucune donnée réécrite à la montée. Les deux statuts neufs (`sent`,
 * `cancelled`) tiennent dans le VARCHAR(24) existant.
 *
 * Écrite à la main (jamais un `migrations:diff` brut, qui ramasse la dérive des autres sessions) ;
 * types relus contre le mapping (`schema:validate`). Horodatée en UTC.
 */
final class Version20261023104500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Règlement : connecteur et issue du terminal sur la tentative';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sale_payment_attempt ADD connector VARCHAR(24) DEFAULT NULL, ADD debited_amount NUMERIC(10, 2) DEFAULT NULL, '
            . 'ADD authorization_number VARCHAR(32) DEFAULT NULL, ADD terminal_transaction_id VARCHAR(64) DEFAULT NULL, ADD terminal_id VARCHAR(64) DEFAULT NULL, '
            . 'ADD card_network VARCHAR(32) DEFAULT NULL, ADD customer_receipt LONGTEXT DEFAULT NULL, ADD merchant_receipt LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Le code d'avant ne connaît ni `sent` ni `cancelled` (il ne relirait pas la tentative). Une
        // demande qui attend le terminal devient « sans issue » : elle tient la vente et se déclare,
        // comme un terminal muet. Une annulation devient un refus : rien n'a été débité, vente libre.
        $this->addSql("UPDATE sale_payment_attempt SET status = 'unresolved' WHERE status = 'sent'");
        $this->addSql("UPDATE sale_payment_attempt SET status = 'refused' WHERE status = 'cancelled'");
        $this->addSql('ALTER TABLE sale_payment_attempt DROP connector, DROP debited_amount, DROP authorization_number, DROP terminal_transaction_id, '
            . 'DROP terminal_id, DROP card_network, DROP customer_receipt, DROP merchant_receipt');
    }
}
