<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les échanges commerciaux — et le prochain geste qu'ils appellent.
 *
 * **`next_action_at` est une DATE, pas un horodatage.** « Rappeler le 12 » est une intention de
 * journée ; réserver un `DATETIME` obligerait à inventer une heure, puis à la comparer.
 *
 * **`idx_crm_activity_followup (establishment_id, next_action_at)`** sert la seule requête chaude du
 * module : les relances d'un établissement. **`idx_crm_activity_customer (customer_id, occurred_at)`**
 * sert la fiche client, qui lit toujours l'historique dans l'ordre.
 *
 * **Aucune donnée n'est créée ici (D66-ter).** Une activité est une trace d'un échange qui a eu lieu :
 * en fabriquer une reviendrait à écrire dans l'historique d'un client un appel que personne n'a passé.
 */
final class Version20260827190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRM : activités commerciales (échange + prochain geste), distinctes des tickets de support.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_commercial_activity (
                id BINARY(16) NOT NULL,
                type VARCHAR(20) NOT NULL,
                occurred_at DATETIME NOT NULL,
                summary LONGTEXT NOT NULL,
                next_action_at DATE DEFAULT NULL,
                next_action VARCHAR(250) DEFAULT NULL,
                establishment_id BINARY(16) NOT NULL,
                customer_id BINARY(16) DEFAULT NULL,
                opportunity_id BINARY(16) DEFAULT NULL,
                author_id BINARY(16) DEFAULT NULL,
                INDEX IDX_6E3D75088565851 (establishment_id),
                INDEX IDX_6E3D75089395C3F3 (customer_id),
                INDEX IDX_6E3D75089A34590F (opportunity_id),
                INDEX IDX_6E3D7508F675F31B (author_id),
                INDEX idx_crm_activity_customer (customer_id, occurred_at),
                INDEX idx_crm_activity_followup (establishment_id, next_action_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql('ALTER TABLE crm_commercial_activity ADD CONSTRAINT FK_6E3D75088565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE crm_commercial_activity ADD CONSTRAINT FK_6E3D75089395C3F3 FOREIGN KEY (customer_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE crm_commercial_activity ADD CONSTRAINT FK_6E3D75089A34590F FOREIGN KEY (opportunity_id) REFERENCES crm_opportunity (id)');
        $this->addSql('ALTER TABLE crm_commercial_activity ADD CONSTRAINT FK_6E3D7508F675F31B FOREIGN KEY (author_id) REFERENCES sec_utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE crm_commercial_activity');
    }
}
