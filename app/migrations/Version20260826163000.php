<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PAY-2 (suite) — la remise porte le compte des échéances écartées faute de préavis.
 *
 * Deux colonnes, écrites à la main (D32). Sans elles, une remise vide parce que tout a été exclu
 * ressemblerait à une remise vide faute d'échéances : le blocage serait invisible, ce qui est
 * exactement l'état qu'on corrige.
 */
final class Version20260826163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PAY-2 : compte et motif des échéances écartées sur la remise SEPA.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sepa_remise ADD nb_exclues INT DEFAULT 0 NOT NULL, ADD motif_exclusion VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sepa_remise DROP nb_exclues, DROP motif_exclusion');
    }
}
