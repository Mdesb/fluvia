<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le nombre d'entrées d'un billet (décision de Maxime du 08/10/2026) : une par défaut, `NULL` =
 * illimité dans la durée de validité.
 *
 * Les produits existants prennent la valeur par défaut, une entrée. Elle n'agit qu'à la vente
 * suivante : les droits d'accès déjà émis ne sont pas réécrits (voir la PR pour la reprise
 * proposée). Une carte et une formule l'ignorent.
 *
 * SQL demandé à Doctrine (`doctrine:schema:update --dump-sql`) puis recopié.
 */
final class Version20261008184217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Offre : nombre d\'entrées d\'un billet (une par défaut, NULL = illimité dans la durée)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE off_produit ADD entry_count INT DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE off_produit DROP entry_count');
    }
}
