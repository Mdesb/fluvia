<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AbonnementFitness.source_sale_line_id : lien retour vers la ligne de vente qui a cree l'abonnement
 * au guichet. Colonne UNIQUE (nullable) qui porte a la fois l'idempotence de la creation en caisse
 * et le lien Vente -> abonnement necessaire a la revocation au remboursement.
 */
final class Version20260907120514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AbonnementFitness.source_sale_line_id : lien retour vers la ligne de vente (guichet), UNIQUE nullable.';
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
