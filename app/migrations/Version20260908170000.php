<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Group — le « produit groupe » composite : forfait réutilisable (`group_product` +
 * `group_product_line`) et articles de panier d'une réservation (`group_booking_item`). Chaque ligne
 * porte un produit du catalogue, une quantité, un prix et un taux de TVA ; la facturation en fait une
 * ligne de devis par article.
 *
 * SQL relevé par `doctrine:schema:update --dump-sql` sur le mapping neuf.
 */
final class Version20260908170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Group : forfaits (group_product/line) et panier de réservation (group_booking_item).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE group_product (id BINARY(16) NOT NULL, label VARCHAR(160) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_554A50A1FF631228 (etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE group_product_line (id BINARY(16) NOT NULL, quantite INT DEFAULT 1 NOT NULL, prix_unitaire_ht NUMERIC(10, 2) DEFAULT '0.00' NOT NULL, group_product_id BINARY(16) NOT NULL, produit_id BINARY(16) NOT NULL, taux_tva_id BINARY(16) NOT NULL, INDEX IDX_B0BADC6E924907E8 (group_product_id), INDEX IDX_B0BADC6EF347EFB (produit_id), INDEX IDX_B0BADC6EF7FEBCCE (taux_tva_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE group_booking_item (id BINARY(16) NOT NULL, quantite INT DEFAULT 1 NOT NULL, prix_unitaire_ht NUMERIC(10, 2) DEFAULT '0.00' NOT NULL, booking_id BINARY(16) NOT NULL, produit_id BINARY(16) NOT NULL, taux_tva_id BINARY(16) NOT NULL, source_id BINARY(16) DEFAULT NULL, INDEX IDX_EBCC4D693301C60 (booking_id), INDEX IDX_EBCC4D69F347EFB (produit_id), INDEX IDX_EBCC4D69F7FEBCCE (taux_tva_id), INDEX IDX_EBCC4D69953C1C61 (source_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql('ALTER TABLE group_product ADD CONSTRAINT FK_554A50A1FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE group_product_line ADD CONSTRAINT FK_B0BADC6E924907E8 FOREIGN KEY (group_product_id) REFERENCES group_product (id)');
        $this->addSql('ALTER TABLE group_product_line ADD CONSTRAINT FK_B0BADC6EF347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id)');
        $this->addSql('ALTER TABLE group_product_line ADD CONSTRAINT FK_B0BADC6EF7FEBCCE FOREIGN KEY (taux_tva_id) REFERENCES compta_taux_tva (id)');
        $this->addSql('ALTER TABLE group_booking_item ADD CONSTRAINT FK_EBCC4D693301C60 FOREIGN KEY (booking_id) REFERENCES group_booking (id)');
        $this->addSql('ALTER TABLE group_booking_item ADD CONSTRAINT FK_EBCC4D69F347EFB FOREIGN KEY (produit_id) REFERENCES off_produit (id)');
        $this->addSql('ALTER TABLE group_booking_item ADD CONSTRAINT FK_EBCC4D69F7FEBCCE FOREIGN KEY (taux_tva_id) REFERENCES compta_taux_tva (id)');
        $this->addSql('ALTER TABLE group_booking_item ADD CONSTRAINT FK_EBCC4D69953C1C61 FOREIGN KEY (source_id) REFERENCES group_product (id)');
    }

    public function down(Schema $schema): void
    {
        // Enfants d'abord : les articles et les lignes référencent le forfait / la réservation.
        $this->addSql('ALTER TABLE group_booking_item DROP FOREIGN KEY FK_EBCC4D69953C1C61');
        $this->addSql('ALTER TABLE group_product_line DROP FOREIGN KEY FK_B0BADC6E924907E8');
        $this->addSql('DROP TABLE group_booking_item');
        $this->addSql('DROP TABLE group_product_line');
        $this->addSql('DROP TABLE group_product');
    }
}
