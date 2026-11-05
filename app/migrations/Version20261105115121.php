<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BORNE AUTOMATIQUE DE VENTE (#341) : la borne, et les commandes qu'elle ouvre.
 *
 * Écrite à la main, pour ces deux tables seulement (jamais un diff brut, qui ratisserait la dérive des
 * autres lots). Les noms d'index et de clés étrangères sont ceux que Doctrine dérive du mapping : la
 * base migrée et la base du harnais (montée depuis le mapping) doivent dire la même chose.
 *
 * Sans perte, dans les deux sens : `up()` ajoute deux tables neuves, `down()` les retire — `kiosk_order`
 * d'abord, qui référence `kiosk`. Les VENTES d'une borne vivent dans `vente_vente`, sur le point de
 * vente de la borne : `down()` ne les touche pas, la chaîne NF525 reste entière.
 */
final class Version20261105115121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Borne automatique de vente (#341) : tables kiosk et kiosk_order.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kiosk (id BINARY(16) NOT NULL, label VARCHAR(120) NOT NULL, active TINYINT DEFAULT 1 NOT NULL, product_ids JSON DEFAULT NULL, recharge_enabled TINYINT DEFAULT 1 NOT NULL, delivery VARCHAR(16) DEFAULT \'ticket\' NOT NULL, idle_seconds INT DEFAULT 60 NOT NULL, exit_code_hash VARCHAR(255) DEFAULT NULL, failed_unlocks INT DEFAULT 0 NOT NULL, unlock_blocked_until DATETIME DEFAULT NULL, device_token_hash VARCHAR(64) DEFAULT NULL, paired_at DATETIME DEFAULT NULL, pairing_code_hash VARCHAR(64) DEFAULT NULL, pairing_code_expires_at DATETIME DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, etablissement_id BINARY(16) NOT NULL, point_of_sale_id BINARY(16) NOT NULL, INDEX IDX_F3171730FF631228 (etablissement_id), UNIQUE INDEX uniq_kiosk_point_of_sale (point_of_sale_id), UNIQUE INDEX uniq_kiosk_device_token (device_token_hash), UNIQUE INDEX uniq_kiosk_pairing_code (pairing_code_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE kiosk_order (id BINARY(16) NOT NULL, recharge_card_code VARCHAR(128) DEFAULT NULL, recharge_line_id BINARY(16) DEFAULT NULL, created_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, abandoned_at DATETIME DEFAULT NULL, badge_status VARCHAR(16) DEFAULT NULL, badge_message VARCHAR(255) DEFAULT NULL, kiosk_id BINARY(16) NOT NULL, sale_id BINARY(16) NOT NULL, INDEX IDX_D3E79194C47A2102 (kiosk_id), UNIQUE INDEX uniq_kiosk_order_sale (sale_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE kiosk ADD CONSTRAINT FK_F3171730FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE kiosk ADD CONSTRAINT FK_F31717306B7E9A73 FOREIGN KEY (point_of_sale_id) REFERENCES caisse_point_de_vente (id)');
        $this->addSql('ALTER TABLE kiosk_order ADD CONSTRAINT FK_D3E79194C47A2102 FOREIGN KEY (kiosk_id) REFERENCES kiosk (id)');
        $this->addSql('ALTER TABLE kiosk_order ADD CONSTRAINT FK_D3E791944A7E4868 FOREIGN KEY (sale_id) REFERENCES vente_vente (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kiosk_order DROP FOREIGN KEY FK_D3E791944A7E4868');
        $this->addSql('ALTER TABLE kiosk_order DROP FOREIGN KEY FK_D3E79194C47A2102');
        $this->addSql('ALTER TABLE kiosk DROP FOREIGN KEY FK_F31717306B7E9A73');
        $this->addSql('ALTER TABLE kiosk DROP FOREIGN KEY FK_F3171730FF631228');
        $this->addSql('DROP TABLE kiosk_order');
        $this->addSql('DROP TABLE kiosk');
    }
}
