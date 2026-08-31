<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Campagnes : le message, et la liste figée de ceux à qui on a écrit.
 *
 * **`marketing_campaign_recipient` est la seule table de ce module qui STOCKE plutôt que de
 * calculer.** Un segment se recalcule à chaque lecture ; cette liste-ci, jamais. Sans elle :
 * l'attribution des ventes serait fausse, le journal RGPD incomplet, et le plafond de sollicitation
 * inerte faute de savoir à qui l'on a déjà écrit.
 *
 * `uniq_marketing_recipient (campaign_id, customer_ref)` : une personne n'apparaît qu'une fois dans
 * une campagne. Sans cette contrainte, un envoi rejoué la compterait deux fois — et le rapport
 * dirait qu'on a touché plus de monde qu'en réalité.
 *
 * `idx_marketing_recipient_pression (customer_ref, channel, notified_at)` sert la seule requête
 * chaude : « combien de fois a-t-on écrit à cette personne, sur ce canal, ces trente derniers
 * jours ». Elle tourne une fois par destinataire et par envoi.
 *
 * Les deux `DEFAULT` sont déclarés au mapping (D32) — dix pour cent de témoin, trente jours de
 * fenêtre. Un défaut écrit à deux endroits finit par dire deux choses.
 */
final class Version20260828000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marketing : campagnes et journal des destinataires.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketing_campaign (
                id BINARY(16) NOT NULL,
                label VARCHAR(120) NOT NULL,
                channel VARCHAR(12) NOT NULL,
                subject VARCHAR(200) NOT NULL,
                body LONGTEXT NOT NULL,
                status VARCHAR(12) NOT NULL,
                scheduled_at DATETIME DEFAULT NULL,
                sent_at DATETIME DEFAULT NULL,
                control_group_percent INT DEFAULT 10 NOT NULL,
                attribution_window_days INT DEFAULT 30 NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                segment_id BINARY(16) NOT NULL,
                INDEX IDX_62B961378565851 (establishment_id),
                INDEX IDX_62B96137DB296AAD (segment_id),
                INDEX idx_marketing_campaign_statut (establishment_id, status),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE marketing_campaign_recipient (
                id BINARY(16) NOT NULL,
                customer_ref BINARY(16) NOT NULL,
                channel VARCHAR(12) NOT NULL,
                outcome VARCHAR(12) NOT NULL,
                exclusion_reason VARCHAR(30) DEFAULT NULL,
                notified_at DATETIME NOT NULL,
                campaign_id BINARY(16) NOT NULL,
                INDEX IDX_313FC6B2F639F774 (campaign_id),
                INDEX idx_marketing_recipient_pression (customer_ref, channel, notified_at),
                UNIQUE INDEX uniq_marketing_recipient (campaign_id, customer_ref),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql('ALTER TABLE marketing_campaign ADD CONSTRAINT FK_62B961378565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE marketing_campaign ADD CONSTRAINT FK_62B96137DB296AAD FOREIGN KEY (segment_id) REFERENCES marketing_segment (id)');
        $this->addSql('ALTER TABLE marketing_campaign_recipient ADD CONSTRAINT FK_313FC6B2F639F774 FOREIGN KEY (campaign_id) REFERENCES marketing_campaign (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketing_campaign_recipient');
        $this->addSql('DROP TABLE marketing_campaign');
    }
}
