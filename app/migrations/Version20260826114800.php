<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D45 : `sale_settlement_correction` — correction de la **ventilation** d'un règlement.
 *
 * −X sur un moyen, +X sur un autre, rattachée à la vente, motivée, signée, datée du **jour du geste**.
 * La vente d'origine n'est jamais modifiée : `Nf525\InalterabiliteListener` refuserait l'écriture, et
 * il aurait raison — le jour où l'on peut réécrire une vente validée, plus aucune vente n'est
 * probante.
 *
 * ⚠ Migration écrite à la main (D32), horodatée en **heure locale** (11:48) et non en UTC. DDL relevé
 * par `SHOW CREATE TABLE` sur la table que Doctrine crée depuis le mapping, noms d'index et de
 * contraintes compris.
 *
 * `alerte_ecart_ref` (D46) : l'écart de caisse que la correction **explique**, s'il est désigné.
 * Référence libre et non clé étrangère — `AlerteEcartCaisse` appartient au module `Caisse`, et la
 * convention du dépôt pour franchir une frontière de module est l'UUID nu ; une contrainte figerait
 * en plus l'ordre de suppression des deux domaines. Le lien vit du côté de la correction parce que
 * l'alerte se déclare **immuable** : c'est la correction qui affirme expliquer, pas l'alerte qui se
 * déclare expliquée.
 *
 * Cette migration a été **complétée avant fusion** plutôt que doublée : elle n'était présente que sur
 * cette branche, et D45 et D46 forment un seul lot. Une fois dans `main`, elle ne bougera plus.
 *
 * Aucune reprise de données : la table naît vide, et rien d'existant ne s'y traduit — une correction
 * est un fait qui a eu lieu, pas un état qu'on reconstitue.
 */
final class Version20260826114800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D45 : table sale_settlement_correction (correction de ventilation de règlement).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sale_settlement_correction (
                id BINARY(16) NOT NULL,
                moyen_debite VARCHAR(32) NOT NULL,
                moyen_credite VARCHAR(32) NOT NULL,
                montant NUMERIC(10, 2) NOT NULL,
                motif LONGTEXT NOT NULL,
                date_heure DATETIME NOT NULL,
                alerte_ecart_ref BINARY(16) DEFAULT NULL,
                vente_id BINARY(16) NOT NULL,
                auteur_id BINARY(16) DEFAULT NULL,
                etablissement_id BINARY(16) NOT NULL,
                INDEX IDX_AF945C677DC7170A (vente_id),
                INDEX IDX_AF945C6760BB6FE6 (auteur_id),
                INDEX IDX_AF945C67FF631228 (etablissement_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE sale_settlement_correction ADD CONSTRAINT FK_AF945C677DC7170A FOREIGN KEY (vente_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE sale_settlement_correction ADD CONSTRAINT FK_AF945C6760BB6FE6 FOREIGN KEY (auteur_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE sale_settlement_correction ADD CONSTRAINT FK_AF945C67FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sale_settlement_correction');
    }
}
