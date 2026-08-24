<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ED-3 — `subscription_provisioning_request` : la trace d'un provisionnement et son verrou d'idempotence.
 *
 * **L'unicité sur `subscription_id` n'est pas un index de confort, c'est la règle métier RG-ED-05.**
 * Un rappel bancaire se répète, parfois assez près pour que deux traitements lisent « aucune demande
 * existante » avant que l'un des deux n'écrive. C'est cette contrainte, et elle seule, qui garantit
 * qu'un abonnement ne produira jamais deux établissements — donc jamais deux facturations. La retirer
 * ne casserait aucun test unitaire et ouvrirait le double provisionnement en production.
 *
 * `establishment_id` est nullable : une demande échouée n'a pas d'établissement, et c'est ce qui
 * distingue un échec d'un succès sans avoir à interpréter le statut seul.
 *
 * ---
 *
 * **Conforme à D32.** Le fichier produit par `doctrine:migrations:diff` a servi de brouillon et a été
 * jeté. Il contenait **104 instructions dont 6 seulement appartenaient à ce lot** : le reste était la
 * dérive des autres sessions — une quarantaine de renommages d'index Finance, DMS, Compta, Stay, et
 * surtout `DROP INDEX support_ft_article_recherche ON support_article_aide`, qui aurait supprimé
 * l'index FULLTEXT de la recherche d'aide dans un lot annonçant la création d'une seule table. Un
 * index FULLTEXT n'étant pas exprimable en mapping ORM, le diff le proposera à la suppression dans le
 * lot de n'importe qui, indéfiniment.
 *
 * **Horodatage en heure locale** (21:36), et non l'heure du conteneur PHP qui tourne en UTC : le
 * brouillon est né `...193818` et se serait classé **avant** `Version20260824200000`, déjà appliquée.
 * Il se serait donc exécuté hors séquence sur toute base existante.
 */
final class Version20260824213600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ED-3 : demande de provisionnement, avec l unicite par abonnement qui porte l idempotence (RG-ED-05).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE subscription_provisioning_request (
                id BINARY(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                attempts INT DEFAULT 0 NOT NULL,
                failure_reason LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                subscription_id BINARY(16) NOT NULL,
                establishment_id BINARY(16) DEFAULT NULL,
                INDEX IDX_A88F29A58565851 (establishment_id),
                UNIQUE INDEX uniq_provisioning_subscription (subscription_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscription_provisioning_request
                ADD CONSTRAINT FK_A88F29A59A1887DC FOREIGN KEY (subscription_id)
                REFERENCES subscription_subscription (id)
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE subscription_provisioning_request
                ADD CONSTRAINT FK_A88F29A58565851 FOREIGN KEY (establishment_id)
                REFERENCES org_etablissement (id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_provisioning_request DROP FOREIGN KEY FK_A88F29A59A1887DC');
        $this->addSql('ALTER TABLE subscription_provisioning_request DROP FOREIGN KEY FK_A88F29A58565851');
        $this->addSql('DROP TABLE subscription_provisioning_request');
    }
}
