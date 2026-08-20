<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ED-1 — catalogue d'offres : formules et options facturables.
 *
 * Généré par `migrations:diff` puis **élagué à la main**. Le diff proposait aussi de supprimer
 * `support_ft_article_recherche` (FULLTEXT), `uniq_article_aide_cle_import` et deux index de Compta :
 * ces index n'existent que dans des migrations en SQL brut, le mapping ORM ne les connaît pas, et le
 * diff les prend donc pour de la dérive. Les appliquer aurait cassé la recherche du module Support en
 * production. Voir la tâche de suivi au tableau.
 */
final class Version20260820120931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ED-1 : catalogue d\'offres (subscription_plan, subscription_plan_option).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subscription_plan (id BINARY(16) NOT NULL, code VARCHAR(64) NOT NULL, label VARCHAR(120) NOT NULL, monthly_price_cents INT NOT NULL, included_capabilities JSON NOT NULL, active TINYINT NOT NULL, UNIQUE INDEX uniq_subscription_plan_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subscription_plan_option (id BINARY(16) NOT NULL, capability VARCHAR(64) NOT NULL, label VARCHAR(120) NOT NULL, monthly_price_cents INT NOT NULL, active TINYINT NOT NULL, UNIQUE INDEX uniq_subscription_option_capability (capability), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE subscription_plan');
        $this->addSql('DROP TABLE subscription_plan_option');
    }
}
