<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ED-2 — abonnement : cycle de vie et options datées.
 *
 * Généré par `migrations:diff` puis **élagué**, comme la migration d'ED-1. Le diff propose à chaque
 * génération de supprimer les index écrits en SQL brut — dont le FULLTEXT du module Support — parce
 * que le mapping ORM ne les connaît pas. Voir C14.
 */
final class Version20260820151917 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ED-2 : abonnements (subscription_subscription, subscription_item).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subscription_item (id BINARY(16) NOT NULL, capability VARCHAR(64) NOT NULL, unit_price_cents INT NOT NULL, active_from DATETIME NOT NULL, active_to DATETIME DEFAULT NULL, subscription_id BINARY(16) NOT NULL, INDEX IDX_282735009A1887DC (subscription_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subscription_subscription (id BINARY(16) NOT NULL, customer_reference VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, ended_at DATETIME DEFAULT NULL, plan_id BINARY(16) NOT NULL, INDEX IDX_CD907844E899029B (plan_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE subscription_item ADD CONSTRAINT FK_282735009A1887DC FOREIGN KEY (subscription_id) REFERENCES subscription_subscription (id)');
        $this->addSql('ALTER TABLE subscription_subscription ADD CONSTRAINT FK_CD907844E899029B FOREIGN KEY (plan_id) REFERENCES subscription_plan (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_item DROP FOREIGN KEY FK_282735009A1887DC');
        $this->addSql('ALTER TABLE subscription_subscription DROP FOREIGN KEY FK_CD907844E899029B');
        $this->addSql('DROP TABLE subscription_item');
        $this->addSql('DROP TABLE subscription_subscription');
    }
}
