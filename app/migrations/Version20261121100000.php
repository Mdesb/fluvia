<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot D3-B de #365 — compte Stripe par exploitant : table `accounting_stripe_account_config`.
 *
 * Écrite à la main (jamais un diff brut), SQL relevé par `doctrine:schema:update --dump-sql`, horodatée
 * en UTC après la dernière migration de `main` (Version20261108190000).
 *
 * La clé secrète et le secret de webhook sont stockés **chiffrés** (`StripeKeyEncryptor`) : la
 * migration ne manipule aucune valeur. Une ligne par profil exploitant (`uniq_stripe_account_config_profile`).
 *
 * `down()` supprime la table, donc les clés saisies : les exploitants retombent sur la clé globale.
 */
final class Version20261121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D3-B #365 : table accounting_stripe_account_config (clé Stripe et secret de webhook chiffrés, 1 par exploitant)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE accounting_stripe_account_config (id BINARY(16) NOT NULL, secret_key_encrypted LONGTEXT NOT NULL, webhook_secret_encrypted LONGTEXT DEFAULT NULL, key_mode VARCHAR(8) NOT NULL, key_updated_at DATETIME DEFAULT NULL, webhook_secret_updated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, business_profile_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_stripe_account_config_profile (business_profile_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE accounting_stripe_account_config ADD CONSTRAINT FK_F9B7602DC591A13 FOREIGN KEY (business_profile_id) REFERENCES compta_profil_exploitant (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE accounting_stripe_account_config');
    }
}
