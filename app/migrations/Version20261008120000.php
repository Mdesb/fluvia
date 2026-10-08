<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `sport_abonnement_fitness.first_month_sale_line_id` (décision de Maxime du 08/10/2026) : la ligne de
 * vente qui a payé au comptoir le premier mois d'un abonnement souscrit à part (#288). Jusqu'ici, ce
 * lien vivait dans `source_sale_line_id`, qui dit aussi « cette vente a créé l'abonnement » : annuler
 * la vente du premier mois résiliait donc l'abonnement (#292). Deux faits, deux colonnes.
 *
 * Colonne neuve et vide : aucune donnée réécrite. Les liens posés au comptoir avant cette migration
 * restent dans `source_sale_line_id` (rien ne les distingue d'une création) et suivent la règle d'avant.
 *
 * SQL demandé à Doctrine (`doctrine:schema:update --dump-sql`) puis recopié, uuid en BINARY(16).
 */
final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sport_abonnement_fitness.first_month_sale_line_id : la ligne qui a payé le premier mois au comptoir, UNIQUE nullable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sport_abonnement_fitness ADD first_month_sale_line_id BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_abo_first_month_sale_line ON sport_abonnement_fitness (first_month_sale_line_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_abo_first_month_sale_line ON sport_abonnement_fitness');
        $this->addSql('ALTER TABLE sport_abonnement_fitness DROP first_month_sale_line_id');
    }
}
