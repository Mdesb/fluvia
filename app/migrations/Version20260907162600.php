<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Preuve d'adresse a la creation d'un compte boutique, et date de verification sur le compte.
 *
 * ⚠ POURQUOI. Un achat en invite laisse ses commandes avec `compte_client_id = NULL` : invisibles
 * de tout compte, meme cree ensuite avec la meme adresse. Le rattachement n'est legitime qu'apres
 * preuve de possession de l'adresse — sans quoi deviner un e-mail donnerait l'historique d'achat
 * de son proprietaire. Cette table porte cette preuve.
 *
 * ⚠ `email_verified_at` EST NULLABLE ET LE RESTE POUR L'EXISTANT. Les comptes deja crees n'ont
 * jamais recu de courriel de confirmation ; les marquer verifies d'office fabriquerait une preuve
 * que personne n'a apportee. Ils restent a NULL, ce qui est le fait.
 */
final class Version20260907162600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jeton de verification d adresse, et date de verification sur le compte boutique.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sec_email_verification_token (
                id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)',
                utilisateur_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)',
                jeton VARCHAR(255) NOT NULL,
                adresse VARCHAR(180) NOT NULL,
                date_expiration DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                utilise TINYINT(1) DEFAULT 0 NOT NULL,
                date_creation DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_EVT_JETON (jeton),
                INDEX IDX_EVT_UTILISATEUR (utilisateur_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE sec_email_verification_token
                ADD CONSTRAINT FK_EVT_UTILISATEUR FOREIGN KEY (utilisateur_id)
                REFERENCES sec_utilisateur (id) ON DELETE CASCADE
        SQL);

        $this->addSql('ALTER TABLE bou_compte_client ADD email_verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sec_email_verification_token DROP FOREIGN KEY FK_EVT_UTILISATEUR');
        $this->addSql('DROP TABLE sec_email_verification_token');
        $this->addSql('ALTER TABLE bou_compte_client DROP email_verified_at');
    }
}
