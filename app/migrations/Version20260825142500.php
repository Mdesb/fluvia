<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ED-7 — `subscription_invoice` : un abonnement, un mois, une facture.
 *
 * **L'unicité `(subscription_id, period_start)` est la règle métier, pas un index de confort.** Un
 * ordonnanceur qui repasse, une relance manuelle après un doute, deux exploitants qui cliquent : sans
 * elle, chacun de ces cas produit une seconde facture et un second prélèvement. La retirer ne casse
 * aucun test unitaire et ouvre la double facturation en production.
 *
 * **`period_start` est une DATE, normalisée au premier jour du mois.** Une période de facturation est
 * un mois entier ; stocker l'instant d'émission ferait de deux appels le 3 et le 4 deux périodes
 * différentes, et l'unicité ne protégerait plus rien.
 *
 * **`invoice_id` est un identifiant, pas une clé étrangère.** `Facturation` est un autre module :
 * l'abonnement s'y réfère sans l'importer, comme `customer_reference` se réfère au CRM. Une clé
 * étrangère ici coupleraient les deux schémas et interdirait d'archiver les factures séparément.
 *
 * Conforme à D32 : brouillon de `doctrine:migrations:diff` jeté — 104 instructions dont 4
 * appartenaient à ce lot. Horodatage en heure locale (14:25) ; le brouillon naissait `122424`, en UTC,
 * et se serait classé avant des migrations déjà appliquées.
 */
final class Version20260825142500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ED-7 : registre des factures dabonnement, avec lunicite qui interdit de facturer deux fois le meme mois.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE subscription_invoice (
                id BINARY(16) NOT NULL,
                period_start DATE NOT NULL,
                invoice_id BINARY(16) NOT NULL,
                issued_at DATETIME NOT NULL,
                total_cents INT NOT NULL,
                subscription_id BINARY(16) NOT NULL,
                INDEX IDX_E370F7DF9A1887DC (subscription_id),
                UNIQUE INDEX uniq_subscription_invoice_periode (subscription_id, period_start),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscription_invoice
                ADD CONSTRAINT FK_E370F7DF9A1887DC FOREIGN KEY (subscription_id)
                REFERENCES subscription_subscription (id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_invoice DROP FOREIGN KEY FK_E370F7DF9A1887DC');
        $this->addSql('DROP TABLE subscription_invoice');
    }
}
