<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les affaires en cours — ce qui vit entre « un client appelle » et « un devis part ».
 *
 * **Le trou que ça comble.** La chaîne devis → commande → facture → relance était déjà écrite et
 * branchée. Ce qui manquait est *avant* : une demande qualifiée, un montant estimé, une échéance.
 * Sans ça, une affaire n'existe dans le logiciel qu'au moment où quelqu'un rédige un devis — donc
 * **toutes celles qui n'y arrivent pas n'ont jamais existé**, et on ne peut rien en apprendre.
 *
 * **`commercial_document_ref` est un `uuid` NU, pas une clé étrangère.** `CommercialDocument` vit dans
 * `App\Facturation` : une relation créerait une dépendance de mapping entre deux modules qui doivent
 * vivre séparément (D2), et c'est la convention suivie par vingt-trois propriétés du dépôt. Le prix
 * est D58 — toute lecture par cette référence doit typer son paramètre `'uuid'`, sans quoi elle rend
 * « aucun devis » sur toutes les affaires, ce qui ressemble à un pipeline neuf.
 *
 * **`loss_reason` est une liste fermée, et le commentaire est à côté.** Un champ libre produirait
 * trente formulations de la même chose, donc rien de dénombrable — c'est-à-dire exactement ce qu'on
 * cherchait à mesurer. Les motifs de perte sont la seule donnée du pipeline qui serve encore dans six
 * mois.
 *
 * DDL relevé par `doctrine:schema:update --dump-sql` sur le mapping (D32).
 */
final class Version20260827100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pipeline commercial : les affaires en cours, avant le devis.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE crm_opportunity (
                id BINARY(16) NOT NULL,
                title VARCHAR(200) NOT NULL,
                stage VARCHAR(20) NOT NULL,
                estimated_amount NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL,
                expected_close_date DATE DEFAULT NULL,
                commercial_document_ref BINARY(16) DEFAULT NULL,
                loss_reason VARCHAR(20) DEFAULT NULL,
                loss_comment LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                customer_id BINARY(16) DEFAULT NULL,
                INDEX IDX_60AF56F38565851 (establishment_id),
                INDEX IDX_60AF56F39395C3F3 (customer_id),
                INDEX idx_crm_opportunity_pipeline (establishment_id, stage),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4'
        );

        $this->addSql(
            'ALTER TABLE crm_opportunity
             ADD CONSTRAINT FK_60AF56F38565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)'
        );
        $this->addSql(
            'ALTER TABLE crm_opportunity
             ADD CONSTRAINT FK_60AF56F39395C3F3 FOREIGN KEY (customer_id) REFERENCES crm_client (id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_opportunity DROP FOREIGN KEY FK_60AF56F39395C3F3');
        $this->addSql('ALTER TABLE crm_opportunity DROP FOREIGN KEY FK_60AF56F38565851');
        $this->addSql('DROP TABLE crm_opportunity');
    }
}
