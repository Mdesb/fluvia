<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SOC-1 — modèle de publication sociale (D14) : `social_post` et `social_publication`.
 *
 * Un message, N publications : une ligne par réseau visé, portant son état, son identifiant distant et
 * son erreur. C'est cette ligne qui portera les statistiques en SOC-3.
 *
 * Conforme à D32 : le fichier généré par `doctrine:migrations:diff` a servi de **brouillon** — on en a
 * gardé les seules instructions provoquées par ce lot (deux tables et leurs contraintes) et jeté tout
 * le reste, qui appartenait à la dérive des autres sessions. Horodatage en heure locale, après la
 * dernière migration présente, et non l'heure UTC du conteneur PHP.
 *
 * `social_publication` ne porte pas d'établissement : elle le tient de son message. Deux colonnes pour
 * le même fait finissent par diverger, et le jour où elles divergent c'est le cloisonnement qui se
 * trompe.
 */
final class Version20260824200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SOC-1 : messages sociaux et leurs publications (une ligne par reseau vise).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE social_post (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                body LONGTEXT NOT NULL,
                scheduled_for DATETIME DEFAULT NULL,
                status VARCHAR(32) DEFAULT 'draft' NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_159BBFE98565851 (establishment_id),
                INDEX idx_social_post_establishment_created (establishment_id, created_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE social_publication (
                id BINARY(16) NOT NULL,
                post_id BINARY(16) NOT NULL,
                account_id BINARY(16) NOT NULL,
                status VARCHAR(32) DEFAULT 'pending' NOT NULL,
                remote_post_id VARCHAR(191) DEFAULT NULL,
                remote_url VARCHAR(512) DEFAULT NULL,
                error_code VARCHAR(64) DEFAULT NULL,
                error_message LONGTEXT DEFAULT NULL,
                attempts INT DEFAULT 0 NOT NULL,
                published_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_DA9897574B89032C (post_id),
                INDEX IDX_DA9897579B6B5FBA (account_id),
                UNIQUE INDEX uniq_social_publication_post_account (post_id, account_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql('ALTER TABLE social_post ADD CONSTRAINT FK_159BBFE98565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE social_publication ADD CONSTRAINT FK_DA9897574B89032C FOREIGN KEY (post_id) REFERENCES social_post (id)');
        $this->addSql('ALTER TABLE social_publication ADD CONSTRAINT FK_DA9897579B6B5FBA FOREIGN KEY (account_id) REFERENCES social_account (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_publication DROP FOREIGN KEY FK_DA9897574B89032C');
        $this->addSql('ALTER TABLE social_publication DROP FOREIGN KEY FK_DA9897579B6B5FBA');
        $this->addSql('ALTER TABLE social_post DROP FOREIGN KEY FK_159BBFE98565851');
        $this->addSql('DROP TABLE social_publication');
        $this->addSql('DROP TABLE social_post');
    }
}
