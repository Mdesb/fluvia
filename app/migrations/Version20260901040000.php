<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * REPRISE INITIALE D'UN CLIENT (T2) — la table des lots, et les deux colonnes qui rendent la
 * reprise réversible.
 *
 * ── ÉCRITE À LA MAIN, ET C'EST LA CONSIGNE (D32) ───────────────────────────────────────────────
 *
 * `migrations:diff` compare les métadonnées à la base **entière** : il ramasse la dérive des autres
 * et la met dans le fichier de celui qui la génère. Le brouillon de `claude-D` contenait 104
 * instructions dont 6 à elle, et faisait tomber la file de messages et la recherche d'aide. Ce
 * fichier ne contient donc que ce que mon lot a provoqué — trois objets, nommés un par un.
 *
 * ── LES DEUX COLONNES DE `crm_client` ──────────────────────────────────────────────────────────
 *
 * `external_ref` porte la clé du client dans le logiciel d'où il vient. C'est elle qui remplace
 * toute heuristique de rapprochement : ni « nom + prénom », ni « nom + date de naissance », qui
 * marchent sur 98 % des lignes et se trompent précisément sur les familles nombreuses, les
 * homonymes et les fratries — les clients d'une piscine municipale.
 *
 * `import_batch_ref` est un identifiant nu, **pas une clé étrangère** (D2). Il permet de savoir ce
 * qu'un lot a créé, donc de le défaire, sans faire dépendre `Crm` d'un module qui ne sert qu'une
 * fois dans la vie d'un client.
 *
 * ⚠ **L'unicité porte sur `(groupe_id, external_ref)`, pas sur l'établissement.** Le fichier client
 * suit l'enseigne : un client appartient au groupe, pas à l'un de ses sites. Une unicité par
 * établissement laisserait le même adhérent entrer deux fois, une fois par site — exactement la
 * duplication que cette reprise existe pour empêcher. C'est un écart assumé à la lettre de la
 * spécification, qui écrit « unique par (établissement, type) », et signalé comme tel.
 *
 * Les deux colonnes sont **nullables** : tous les clients déjà en base ont été créés dans
 * l'application, pas repris. Les rendre obligatoires demanderait d'inventer une référence pour des
 * gens qui n'en ont pas.
 *
 * ── AUCUN `DROP` ───────────────────────────────────────────────────────────────────────────────
 *
 * Cette migration n'en contient pas, et c'est vérifiable ligne à ligne : elle crée une table et
 * ajoute deux colonnes. Le `down()` défait exactement cela.
 */
final class Version20260901040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reprise initiale : table des lots d\'import, et rattachement des clients repris.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE import_batch (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                created_by_id BINARY(16) DEFAULT NULL,
                type VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(128) NOT NULL,
                file_size INT NOT NULL,
                content_hash VARCHAR(64) NOT NULL,
                content LONGTEXT NOT NULL,
                row_count INT NOT NULL,
                errors JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                applied_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_rows INT NOT NULL,
                INDEX IDX_IMPORT_BATCH_ETAB_STATUS (establishment_id, status),
                INDEX IDX_IMPORT_BATCH_ETAB_TYPE (establishment_id, type),
                INDEX IDX_IMPORT_BATCH_CREATED_BY (created_by_id),
                UNIQUE INDEX UNIQ_IMPORT_BATCH_ETAB_HASH (establishment_id, content_hash),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE import_batch
                ADD CONSTRAINT FK_IMPORT_BATCH_ETAB FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id),
                ADD CONSTRAINT FK_IMPORT_BATCH_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES sec_utilisateur (id) ON DELETE SET NULL
            SQL);

        $this->addSql('ALTER TABLE crm_client ADD external_ref VARCHAR(128) DEFAULT NULL, ADD import_batch_ref BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_client_groupe_external_ref ON crm_client (groupe_id, external_ref)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_client_groupe_external_ref ON crm_client');
        $this->addSql('ALTER TABLE crm_client DROP external_ref, DROP import_batch_ref');
        $this->addSql('ALTER TABLE import_batch DROP FOREIGN KEY FK_IMPORT_BATCH_ETAB');
        $this->addSql('ALTER TABLE import_batch DROP FOREIGN KEY FK_IMPORT_BATCH_CREATED_BY');
        $this->addSql('DROP TABLE import_batch');
    }
}
