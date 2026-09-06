<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `sec_utilisateur.kind` : exploitant ou client final — audit du 06/09, constat 4.
 *
 * Les comptes créés par la boutique publique vivaient dans la même table que les exploitants, sans
 * rien qui les distingue ; leur jeton franchissait toutes les portes « connecté, et rien de plus ».
 * La nature du compte est posée ici, avec `operator` par défaut — un compte existant est un compte
 * d'exploitant sauf preuve du contraire — puis rectifiée pour tous ceux qu'un `bou_compte_client`
 * désigne : ceux-là sont des clients finals, et le sont depuis leur création.
 *
 * Le `ALTER` est celui de `doctrine:schema:update --dump-sql` ; le `UPDATE` est à nous — Doctrine ne
 * sait rien de ce qu'une colonne neuve doit contenir.
 */
final class Version20260906140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sec_utilisateur.kind : nature du compte (operator|customer), rectifiée depuis bou_compte_client.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sec_utilisateur ADD kind VARCHAR(16) DEFAULT 'operator' NOT NULL");
        $this->addSql(<<<'SQL'
            UPDATE sec_utilisateur u
            INNER JOIN bou_compte_client c ON c.utilisateur_id = u.id
            SET u.kind = 'customer'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sec_utilisateur DROP kind');
    }
}
