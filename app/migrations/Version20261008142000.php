<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `vente_ligne` : le taux de TVA gravé sur la ligne à sa création (ticket opposable, lot 6, M-c ;
 * D123, G-7). La valeur, la catégorie EN 16931, le libellé, et la référence du `compta_taux_tva` d'où
 * il vient : une référence seule ne figerait rien, le taux se modifie par `PATCH`.
 *
 * Quatre colonnes neuves, nullables et vides : aucune donnée fiscale fabriquée pour les ventes
 * antérieures (D66-ter). Elles restent sans taux, et le diront.
 *
 * SQL demandé à Doctrine (`doctrine:schema:update --dump-sql`, filtré sur `vente_ligne`) puis recopié,
 * uuid en BINARY(16).
 */
final class Version20261008142000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'vente_ligne : taux de TVA gravé (taux_tva, taux_tva_categorie, taux_tva_libelle, taux_tva_ref), nullables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_ligne ADD taux_tva NUMERIC(5, 2) DEFAULT NULL, ADD taux_tva_categorie VARCHAR(4) DEFAULT NULL, ADD taux_tva_libelle VARCHAR(80) DEFAULT NULL, ADD taux_tva_ref BINARY(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_ligne DROP taux_tva, DROP taux_tva_categorie, DROP taux_tva_libelle, DROP taux_tva_ref');
    }
}
