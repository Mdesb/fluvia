<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les tables du socle de signature électronique (`App\Signature`) et du contrat d'abonnement signé.
 *
 * Écrite À LA MAIN : `migrations:diff` ratisserait la dérive des autres sessions ouvertes sur ce
 * dépôt (et horodaterait en UTC). La DDL est reprise TELLE QUELLE de `doctrine:schema:create
 * --dump-sql` sur le mapping de ces deux entités — donc cohérente avec le mapping par construction.
 *
 * Deux tables neuves + leurs clés étrangères vers des tables existantes (org_etablissement,
 * crm_client, sec_utilisateur, sport_abonnement_fitness). Rien d'existant n'est modifié : la
 * migration ne peut qu'ajouter, jamais casser une donnée en place.
 */
final class Version20260909143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables electronic_signature + subscription_contract (signature avancée scellée + contrat signé).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE electronic_signature (id BINARY(16) NOT NULL, document_type VARCHAR(24) NOT NULL, target_type VARCHAR(64) NOT NULL, target_id BINARY(16) NOT NULL, document_hash VARCHAR(128) NOT NULL, signature_image LONGTEXT DEFAULT NULL, signer_name VARCHAR(255) NOT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(512) DEFAULT NULL, signed_at DATETIME NOT NULL, sequence_number BIGINT NOT NULL, hash VARCHAR(128) NOT NULL, previous_hash VARCHAR(128) DEFAULT NULL, seal VARCHAR(512) NOT NULL, canonical_payload JSON NOT NULL, etablissement_id BINARY(16) NOT NULL, signer_id BINARY(16) DEFAULT NULL, operator_id BINARY(16) DEFAULT NULL, INDEX IDX_54D283F1FF631228 (etablissement_id), INDEX IDX_54D283F19588C067 (signer_id), INDEX IDX_54D283F1584598A3 (operator_id), UNIQUE INDEX uniq_electronic_signature_seq (etablissement_id, sequence_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subscription_contract (id BINARY(16) NOT NULL, document_text LONGTEXT NOT NULL, created_at DATETIME NOT NULL, subscription_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, signature_id BINARY(16) DEFAULT NULL, INDEX IDX_8E2177759A1887DC (subscription_id), INDEX IDX_8E217775FF631228 (etablissement_id), UNIQUE INDEX UNIQ_8E217775ED61183A (signature_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE electronic_signature ADD CONSTRAINT FK_54D283F1FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE electronic_signature ADD CONSTRAINT FK_54D283F19588C067 FOREIGN KEY (signer_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE electronic_signature ADD CONSTRAINT FK_54D283F1584598A3 FOREIGN KEY (operator_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE subscription_contract ADD CONSTRAINT FK_8E2177759A1887DC FOREIGN KEY (subscription_id) REFERENCES sport_abonnement_fitness (id)');
        $this->addSql('ALTER TABLE subscription_contract ADD CONSTRAINT FK_8E217775FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE subscription_contract ADD CONSTRAINT FK_8E217775ED61183A FOREIGN KEY (signature_id) REFERENCES electronic_signature (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_contract DROP FOREIGN KEY FK_8E217775ED61183A');
        $this->addSql('ALTER TABLE subscription_contract DROP FOREIGN KEY FK_8E2177759A1887DC');
        $this->addSql('ALTER TABLE subscription_contract DROP FOREIGN KEY FK_8E217775FF631228');
        $this->addSql('ALTER TABLE electronic_signature DROP FOREIGN KEY FK_54D283F1FF631228');
        $this->addSql('ALTER TABLE electronic_signature DROP FOREIGN KEY FK_54D283F19588C067');
        $this->addSql('ALTER TABLE electronic_signature DROP FOREIGN KEY FK_54D283F1584598A3');
        $this->addSql('DROP TABLE subscription_contract');
        $this->addSql('DROP TABLE electronic_signature');
    }
}
