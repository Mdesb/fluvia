<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Alerte NF525 « chaîne fiscale bloquée » (`features/nf525-alerte-scellement`, étape 3) — deux tables
 * nouvelles, aucune table existante modifiée.
 *
 * **Une alerte ouverte par chaîne, garantie par la base** : `open_chain_key` est une colonne générée
 * virtuelle (`chain_key` si `status = 'open'`, sinon `NULL`) portant un index `UNIQUE` — MariaDB exclut
 * les `NULL` d'un index unique. Même mécanisme que `Version20260901090100`. ⚠ `status` et `chain_key`
 * sont NOT NULL : NULL sous UNIQUE autoriserait les doublons. Reproduit côté schéma de test par
 * `App\Platform\Doctrine\Nf525AlertSchemaListener`.
 *
 * Aucune clé étrangère vers point de vente, profil ou journal (`point_of_sale_id`, `operator_profile_id`,
 * `journal_id` : identifiants simples) — l'alerte s'écrit sur une seconde connexion et une FK la ferait
 * attendre les verrous de la transaction de l'appelant. Seule la table de liaison porte des FK
 * (alerte, ON DELETE CASCADE ; établissement).
 *
 * La FK `establishment_id` → `org_etablissement` est RESTRICT (pas de ON DELETE), volontairement : NF525,
 * on ne purge pas ; un établissement lié à une alerte (même résolue) ne se supprime pas (comme
 * `finance_treasury_cash_alert`).
 *
 * Noms d'index et de clés étrangères : ceux que Doctrine attend (`doctrine:schema:update --dump-sql`),
 * pour qu'`bin/verifier-derive-schema.sh` ne propose aucun renommage.
 */
final class Version20261118130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Platform (alerte NF525) : nouvelles tables platform_nf525_alert et platform_nf525_alert_establishment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_nf525_alert (
                id BINARY(16) NOT NULL,
                reference VARCHAR(16) NOT NULL,
                chain_type VARCHAR(16) NOT NULL,
                chain_key VARCHAR(100) NOT NULL,
                chain_label VARCHAR(160) NOT NULL,
                point_of_sale_id BINARY(16) DEFAULT NULL,
                operator_profile_id BINARY(16) DEFAULT NULL,
                journal_id BINARY(16) DEFAULT NULL,
                cause VARCHAR(24) NOT NULL,
                origin VARCHAR(16) NOT NULL,
                status VARCHAR(10) DEFAULT 'open' NOT NULL,
                refusal_count INT DEFAULT 0 NOT NULL,
                detection_count INT DEFAULT 0 NOT NULL,
                first_event_at DATETIME NOT NULL,
                last_event_at DATETIME NOT NULL,
                technical_detail LONGTEXT NOT NULL,
                note LONGTEXT DEFAULT NULL,
                note_at DATETIME DEFAULT NULL,
                note_by VARCHAR(180) DEFAULT NULL,
                resolved_at DATETIME DEFAULT NULL,
                open_chain_key VARCHAR(100) GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN chain_key END) VIRTUAL,
                INDEX idx_nf525_alert_status_last (status, last_event_at),
                UNIQUE INDEX uniq_nf525_alert_reference (reference),
                UNIQUE INDEX uniq_nf525_alert_open_chain (open_chain_key),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_nf525_alert_establishment (
                alert_id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                INDEX IDX_D1118A2193035F72 (alert_id),
                INDEX IDX_D1118A218565851 (establishment_id),
                PRIMARY KEY (alert_id, establishment_id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE platform_nf525_alert_establishment ADD CONSTRAINT FK_D1118A2193035F72 FOREIGN KEY (alert_id) REFERENCES platform_nf525_alert (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE platform_nf525_alert_establishment ADD CONSTRAINT FK_D1118A218565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_nf525_alert_establishment');
        $this->addSql('DROP TABLE platform_nf525_alert');
    }
}
