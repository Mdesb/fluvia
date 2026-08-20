<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Finance\SupplierInvoice\Entity\SupplierInvoiceLine` (lot FIN-2) — nouvelle table, ligne d'une
 * facture fournisseur (créée/éditée séparément de la facture, même patron que `stk_ligne_commande_achat`).
 */
final class Version20260820090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance (FIN-2) : nouvelle table finance_supplier_invoice_line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_supplier_invoice_line (
                id BINARY(16) NOT NULL,
                supplier_invoice_id BINARY(16) NOT NULL,
                description VARCHAR(255) NOT NULL,
                stock_article_id BINARY(16) DEFAULT NULL,
                quantity NUMERIC(12, 3) NOT NULL,
                unit_price_excl_tax NUMERIC(12, 4) NOT NULL,
                vat_rate_id BINARY(16) NOT NULL,
                expense_nature_code VARCHAR(64) NOT NULL,
                amount_excl_tax NUMERIC(12, 2) NOT NULL,
                vat_amount NUMERIC(12, 2) NOT NULL,
                amount_incl_tax NUMERIC(12, 2) NOT NULL,
                INDEX idx_supplier_invoice_line_invoice (supplier_invoice_id),
                INDEX idx_supplier_invoice_line_stock_article (stock_article_id),
                INDEX idx_supplier_invoice_line_vat_rate (vat_rate_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_supplier_invoice_line ADD CONSTRAINT FK_FIN_SINV_LINE_INVOICE FOREIGN KEY (supplier_invoice_id) REFERENCES finance_supplier_invoice (id)');
        $this->addSql('ALTER TABLE finance_supplier_invoice_line ADD CONSTRAINT FK_FIN_SINV_LINE_STOCK_ARTICLE FOREIGN KEY (stock_article_id) REFERENCES stk_article (id)');
        $this->addSql('ALTER TABLE finance_supplier_invoice_line ADD CONSTRAINT FK_FIN_SINV_LINE_VAT_RATE FOREIGN KEY (vat_rate_id) REFERENCES compta_taux_tva (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_supplier_invoice_line');
    }
}
