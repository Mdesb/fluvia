<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CQ-7 (`spec-cq7-validite-recharge.md`, RG-CQ7-01) : mode de renouvellement de la validité d'une carte
 * multi-entrées à la recharge, configurable par produit-carte — `extend` (défaut, comportement CQ-1/D26)
 * ou `keep` (conserver l'échéance existante).
 *
 * ⚠ Migration écrite à la main (DDL, même précaution que `Version20260824090000.php` —
 * `doctrine:migrations:diff` propose systématiquement des suppressions d'index sur ce dépôt). Relue
 * ligne à ligne : un seul `ALTER TABLE` additif sur `off_carte_multi_entrees`, aucun index, aucune
 * contrainte, aucune colonne d'un autre module touchée. La valeur de repli `extend` reproduit à
 * l'identique le comportement livré par CQ-1 sur toutes les cartes existantes (non-régression).
 */
final class Version20260824100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CQ-7 : recharge_validity_mode sur CarteMultiEntrees (défaut extend = comportement CQ-1/D26).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE off_carte_multi_entrees ADD recharge_validity_mode VARCHAR(16) DEFAULT 'extend' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE off_carte_multi_entrees DROP recharge_validity_mode');
    }
}
