<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `sport_abonnement_fitness.source_sale_line_id` : lien retour vers la ligne de vente
 * (`App\Vente\Entity\LigneVente`) qui a créé l'abonnement au comptoir (spec-caisse-abonnement CP-1
 * G-5, plan CP-2 É2). Colonne UNIQUE (nullable — seuls les abonnements nés d'une vente comptoir la
 * portent ; ceux nés en ligne ou par réengagement restent NULL) : porte à la fois l'idempotence de
 * la création au comptoir (`App\Membership\Repository\SubscriptionRepository::findOneBySourceSaleLine`)
 * et le lien Vente → abonnement.
 *
 * Table NON renommée : `sport_abonnement_fitness` reste le nom physique (cf. docblock de
 * `App\Membership\Entity\Membership` — sept clés étrangères pointent sur cette table).
 */
final class Version20260916180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sport_abonnement_fitness.source_sale_line_id : lien retour vers la ligne de vente (comptoir), UNIQUE nullable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sport_abonnement_fitness ADD source_sale_line_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('CREATE UNIQUE INDEX uniq_abo_source_sale_line ON sport_abonnement_fitness (source_sale_line_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_abo_source_sale_line ON sport_abonnement_fitness');
        $this->addSql('ALTER TABLE sport_abonnement_fitness DROP source_sale_line_id');
    }
}
