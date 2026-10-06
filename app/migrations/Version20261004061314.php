<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Webhooks partenaires (04/10/2026, spec API partenaire v1 §3.3) : l'abonnement d'une application
 * (URL et secret chiffrés au repos) et la trace de chaque livraison.
 *
 * DDL recopié de `doctrine:schema:update --dump-sql` sur une base bâtie par les migrations — ces seules
 * lignes, rien de la dérive des autres. Joué up/down/up sur base vide.
 */
final class Version20261004061314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'API partenaire : abonnements webhooks et livraisons';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE public_api_webhook_subscription (id BINARY(16) NOT NULL, encrypted_url LONGTEXT NOT NULL, url_host VARCHAR(255) NOT NULL, events JSON NOT NULL, encrypted_secret LONGTEXT NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, secret_rotated_at DATETIME NOT NULL, application_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_public_api_webhook_application (application_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE public_api_webhook_delivery (id BINARY(16) NOT NULL, event_id BINARY(16) NOT NULL, event_type VARCHAR(64) NOT NULL, body LONGTEXT NOT NULL, status VARCHAR(12) DEFAULT \'pending\' NOT NULL, attempts INT DEFAULT 0 NOT NULL, last_error VARCHAR(255) DEFAULT NULL, last_attempt_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, queued_at DATETIME DEFAULT NULL, subscription_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, INDEX IDX_1E98C9609A1887DC (subscription_id), INDEX IDX_1E98C960FF631228 (etablissement_id), INDEX idx_public_api_webhook_delivery_status (status, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE public_api_webhook_subscription ADD CONSTRAINT FK_2FCD71303E030ACD FOREIGN KEY (application_id) REFERENCES public_api_application (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE public_api_webhook_delivery ADD CONSTRAINT FK_1E98C9609A1887DC FOREIGN KEY (subscription_id) REFERENCES public_api_webhook_subscription (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE public_api_webhook_delivery ADD CONSTRAINT FK_1E98C960FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE public_api_webhook_delivery');
        $this->addSql('DROP TABLE public_api_webhook_subscription');
    }
}
