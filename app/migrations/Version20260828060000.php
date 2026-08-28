<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Photos de produit — une table de liens, aucun fichier.
 *
 * Le contenu vit dans le DMS ; cette table ne porte que la référence, l'ordre et le texte
 * alternatif. DDL relevé sur le mapping (D32).
 *
 * `ON DELETE CASCADE` sur le produit : une photo sans produit n'a plus d'adresse ni de sens, et la
 * laisser derrière ferait grossir une table que personne ne relit. Le DOCUMENT, lui, survit — le
 * DMS a ses propres règles de conservation, et un module consommateur ne les contourne pas.
 */
final class Version20260828060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Photos de produit : lien vers un document DMS, ordre d’affichage, texte alternatif.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE offre_product_photo (
                id BINARY(16) NOT NULL,
                produit_id BINARY(16) NOT NULL,
                document_ref BINARY(16) NOT NULL,
                position INT DEFAULT 0 NOT NULL,
                alt_text VARCHAR(160) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_2D02FAF1F347EFB (produit_id),
                INDEX idx_offre_product_photo_produit (produit_id, position),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(
            'ALTER TABLE offre_product_photo ADD CONSTRAINT FK_2D02FAF1F347EFB '
            . 'FOREIGN KEY (produit_id) REFERENCES off_produit (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE offre_product_photo');
    }
}
