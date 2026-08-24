<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ED-4 — `subscription_support_access` : l'accès d'assistance, nominatif et daté (RG-ED-07).
 *
 * **`expires_at` est NOT NULL, et c'est la contrainte qui porte la règle.** RG-ED-07 exige un accès
 * *borné dans le temps* ; une colonne nullable rendrait l'accès permanent possible par simple oubli,
 * et un accès permanent aux données de tous les clients est exactement ce que la règle interdit. La
 * base refuse donc ce que le code pourrait laisser passer.
 *
 * `revoked_at` est nullable, lui : une révocation est un événement qui peut ne pas avoir eu lieu. Et
 * la ligne n'est jamais supprimée — c'est l'historique de qui a pu voir quoi, sans valeur s'il peut
 * disparaître.
 *
 * Conforme à D32 : le brouillon `doctrine:migrations:diff` contenait **102 instructions dont 6
 * appartenaient à ce lot**. Le reste — renommages d'index Finance, DMS, Compta, Stay, et la
 * suppression de l'index FULLTEXT du module Support — a été jeté. Horodatage en heure locale (22:45),
 * après `Version20260824224000` ; le brouillon naissait `203715`, en UTC, et se serait classé avant
 * quatre migrations déjà appliquées.
 */
final class Version20260824224500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ED-4 : acces dassistance borne et nominatif (RG-ED-07).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE subscription_support_access (
                id BINARY(16) NOT NULL,
                granted_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME DEFAULT NULL,
                reason LONGTEXT NOT NULL,
                granted_by VARCHAR(180) DEFAULT NULL,
                grantee_id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                INDEX IDX_A492E5609DB5A748 (grantee_id),
                INDEX IDX_A492E5608565851 (establishment_id),
                INDEX idx_support_access_grantee_target (grantee_id, establishment_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscription_support_access
                ADD CONSTRAINT FK_A492E5609DB5A748 FOREIGN KEY (grantee_id)
                REFERENCES sec_utilisateur (id)
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscription_support_access
                ADD CONSTRAINT FK_A492E5608565851 FOREIGN KEY (establishment_id)
                REFERENCES org_etablissement (id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_support_access DROP FOREIGN KEY FK_A492E5609DB5A748');
        $this->addSql('ALTER TABLE subscription_support_access DROP FOREIGN KEY FK_A492E5608565851');
        $this->addSql('DROP TABLE subscription_support_access');
    }
}
