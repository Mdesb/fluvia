<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le panier retient **quelle version des CGV** le client a acceptée.
 *
 * **La moitié manquante de la preuve.** Le panier horodatait déjà le consentement RGPD, et
 * `legal_document` conserve chaque version publiée des conditions de vente — mais rien ne reliait les
 * deux. On savait **quand** le client avait accepté, jamais **ce qu'il avait accepté**.
 *
 * Des CGV ne sont opposables que dans la version que le client a pu lire au moment où il a payé. Un
 * exploitant qui les modifie en mars ne peut rien invoquer pour une commande de janvier — et sans ce
 * lien, il ne peut même pas montrer laquelle s'appliquait.
 *
 * > **Conserver le texte sans conserver ce que le client a vu, c'est archiver la moitié de la preuve.**
 *
 * **`cgv_document_ref` est un `uuid` nu, pas une clé étrangère.** `LegalDocument` vit dans
 * `App\Legal` : une relation créerait une dépendance de mapping entre deux modules qui doivent vivre
 * séparément (D2). Le prix est D58 — toute lecture par cette référence type son paramètre.
 *
 * **Nullable, et cela ne se rattrapera pas.** Les paniers antérieurs n'ont rien accepté de traçable ;
 * leur attribuer rétroactivement une version leur ferait porter un texte qu'ils n'ont pas vu, ce qui
 * serait pire que l'absence.
 *
 * DDL relevé par `doctrine:schema:update --dump-sql` sur le mapping (D32).
 */
final class Version20260827110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le panier retient la version des CGV acceptée par le client.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE bou_panier
             ADD cgv_version_acceptee INT DEFAULT NULL,
             ADD cgv_document_ref BINARY(16) DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bou_panier DROP cgv_version_acceptee, DROP cgv_document_ref');
    }
}
