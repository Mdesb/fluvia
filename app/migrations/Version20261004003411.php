<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Limite de débit de l'API partenaire `/v1` : le stockage partagé (04/10/2026, spec API partenaire v1 §3.1).
 *
 * Deux tables que Symfony gère lui-même et que Doctrine ajoute à son schéma par ses écouteurs
 * (`DoctrineDbalCacheAdapterSchemaListener`, `LockStoreSchemaListener`) : le DDL est celui que rend
 * `doctrine:schema:update --dump-sql` sur une base bâtie par les migrations, recopié tel quel.
 *
 *  - `cache_items` : le pool `cache.public_api_rate_limit` — les fenêtres glissantes, une ligne par clé ;
 *  - `lock_keys`   : le verrou `lock.public_api` (DoctrineDbalStore) qui sérialise leur lecture-écriture.
 *
 * Créées ici plutôt qu'au premier usage (les deux classes savent le faire) : une table née d'une requête
 * en production est une table qu'aucune migration ne connaît, que `down()` n'enlève pas, et que la
 * vérification de dérive signalerait sans que personne sache d'où elle vient.
 */
final class Version20261004003411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'API partenaire : tables du compteur partagé de la limite de débit (cache_items, lock_keys)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cache_items (item_id VARBINARY(255) NOT NULL, item_data MEDIUMBLOB NOT NULL, item_lifetime INT UNSIGNED DEFAULT NULL, item_time INT UNSIGNED NOT NULL, PRIMARY KEY (item_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT UNSIGNED NOT NULL, PRIMARY KEY (key_id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lock_keys');
        $this->addSql('DROP TABLE cache_items');
    }
}
