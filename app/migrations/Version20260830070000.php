<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Produit complémentaire : le casier avec l'entrée, le bonnet avec le cours.
 *
 * Première des neuf typologies de produits listées par Maxime le 30/08, et la seule dont rien
 * n'existait — les huit autres avaient au moins une amorce.
 *
 * ── POURQUOI UN LIEN ENTRE PRODUITS PLUTÔT QU'UNE OPTION ───────────────────────────────────────
 *
 * `App\OptionProduit` sait déjà attacher un supplément avec impact prix et décrément de stock. Mais
 * vérifié dans `AjoutLigneHandler` : l'option ne crée pas sa propre ligne, elle modifie le prix
 * unitaire de la ligne du parent. La ligne porte donc la catégorie comptable du parent — **donc son
 * taux de TVA**. Une serviette à 20 % vendue en option d'une entrée à 5,5 % serait comptabilisée à
 * 5,5 %.
 *
 * Le complément est un produit entier et fait sa propre ligne : sa TVA, son compte, sa grille
 * tarifaire, son billet éventuel. On ne remplace pas les options — elles restent le bon outil pour
 * ce qui n'existe pas seul et partage la TVA du parent.
 *
 * ── TOUT EST EN ANGLAIS, ET CE N'EST PAS UN CHOIX DE STYLE ─────────────────────────────────────
 *
 * ⚠ Une première version nommait la table `off_produit_complementaire`. D5 a refusé le commit, et
 * il avait raison : un fichier neuf n'a aucune raison d'introduire du vocabulaire français. Les
 * valeurs d'énumération sont anglaises pour la même raison — `suggested`, pas `suggere` : une
 * valeur stockée est une déclaration. Les libellés vus par l'exploitant viendront de l'écran.
 *
 * ── `ON DELETE CASCADE` DES DEUX CÔTÉS, ET LE SECOND EST LE MOINS ÉVIDENT ──────────────────────
 *
 * Côté source, évident : le produit disparaît, ses liens n'ont plus d'objet.
 *
 * ⚠ Côté complément, c'est une protection : un lien qui survivrait à la suppression du complément
 * ferait proposer à la caisse un produit qui n'existe plus — et en mode `required`, il rendrait la
 * vente du parent **impossible** sans que rien n'explique pourquoi.
 *
 * DDL relevé sur le mapping (D32), noms d'index et de contraintes compris.
 */
final class Version20260830070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Produit complémentaire : lien produit → produit, avec son mode de présentation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE off_complementary_product (
                id BINARY(16) NOT NULL,
                mode VARCHAR(16) DEFAULT 'suggested' NOT NULL,
                default_quantity INT DEFAULT 1 NOT NULL,
                product_id BINARY(16) NOT NULL,
                complement_id BINARY(16) NOT NULL,
                INDEX IDX_DE3186E940D9D0AA (complement_id),
                INDEX idx_complementary_product_source (product_id),
                UNIQUE INDEX uniq_complementary_product_pair (product_id, complement_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql('ALTER TABLE off_complementary_product ADD CONSTRAINT FK_DE3186E94584665A FOREIGN KEY (product_id) REFERENCES off_produit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE off_complementary_product ADD CONSTRAINT FK_DE3186E940D9D0AA FOREIGN KEY (complement_id) REFERENCES off_produit (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE off_complementary_product');
    }
}
