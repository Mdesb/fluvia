<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CQ-7 (D26) : `RechargeValidityMode` sur `CarteMultiEntrees` — ce qu'une recharge fait à la
 * validité de la carte, prolongation par défaut.
 *
 * ⚠ Migration écrite à la main (même précaution que `Version20260824090000.php` :
 * `doctrine:migrations:diff` propose systématiquement des suppressions d'index sur ce dépôt).
 * Relue ligne à ligne : un seul `ALTER TABLE`, sur `off_carte_multi_entrees`, une seule colonne
 * ajoutée — aucun index, aucune contrainte, aucune colonne d'un autre module n'est touchée.
 *
 * Colonne NOT NULL avec DEFAULT constant : la valeur de repli est la même pour toutes les lignes
 * existantes (D26 — la prolongation est le comportement livré, donc aussi celui qu'on prête aux
 * cartes déjà en base), un simple ADD COLUMN suffit et reste rejouable.
 */
final class Version20260824123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CQ-7 : rechargeValidityMode sur CarteMultiEntrees (défaut extend, D26).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE off_carte_multi_entrees ADD recharge_validity_mode VARCHAR(12) DEFAULT 'extend' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE off_carte_multi_entrees DROP recharge_validity_mode');
    }
}
