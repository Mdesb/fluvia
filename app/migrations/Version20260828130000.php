<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Planning d'ouverture : tranches hebdomadaires, exceptions datées, et l'interrupteur du refus.
 *
 * ── ÉCRITE À LA MAIN, ET C'EST LA RÈGLE ICI ─────────────────────────────────────────────────────
 *
 * `doctrine:migrations:diff` est destructeur sur ce dépôt quand plusieurs sessions travaillent en
 * parallèle : lancé depuis un worktree à jour, il ramasse la dérive des autres et propose de
 * supprimer des tables et des index qui ne sont pas les siens (24/08). Le DDL ci-dessous est relevé
 * sur le mapping de `App\Opening\Entity\**`, et rien d'autre.
 *
 * L'horodatage du nom est en heure LOCALE. Le conteneur PHP tourne en UTC, deux heures derrière :
 * une migration générée automatiquement à 13 h naîtrait `...110000` et se classerait donc AVANT des
 * migrations déjà appliquées — exécutée hors séquence sur toute base existante.
 *
 * ── LES DEUX `ON DELETE` NE DISENT PAS LA MÊME CHOSE ────────────────────────────────────────────
 *
 * Sur l'ESPACE, `CASCADE` : une tranche horaire attachée à un espace supprimé n'a plus d'objet, et
 * la laisser derrière ferait porter au site des horaires que plus rien ne rattache — donc, réglage
 * activé, un refus dont personne ne trouverait la cause.
 *
 * ── LES NOMS D'INDEX SONT CEUX QUE DOCTRINE GÉNÈRE ─────────────────────────────────────────────
 *
 * `IDX_4A8BE64C8565851` est illisible, et c'est pourtant le bon nom. Un index de clé étrangère
 * nommé à la main est relu par Doctrine comme une différence : `migrations:diff` proposera de le
 * renommer, indéfiniment, et ces renommages finiront un jour dans la migration de quelqu'un d'autre
 * (C14, ouverte depuis le 20/08 pour cette raison exacte). Les index COMPOSITES, eux, sont déclarés
 * au mapping et gardent leur nom parlant.
 *
 * Vérifié après coup : `doctrine:schema:update --dump-sql` sur une base migrée depuis zéro ne
 * propose plus rien sur `ouverture_*`.
 *
 * Sur l'ÉTABLISSEMENT, rien : la contrainte est nue, et supprimer un établissement qui porte encore
 * un planning ÉCHOUE. C'est voulu. Un établissement ne se supprime pas à la légère dans ce produit,
 * et un `CASCADE` silencieux ferait disparaître la configuration d'accès d'un site en même temps
 * qu'une erreur de manipulation.
 */
final class Version20260828130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning d’ouverture : tranches hebdomadaires, exceptions datées, réglage d’application.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE opening_slot (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                space_id BINARY(16) DEFAULT NULL,
                weekday SMALLINT NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                label VARCHAR(80) DEFAULT NULL,
                INDEX IDX_4A8BE64C8565851 (establishment_id),
                INDEX IDX_4A8BE64C23575340 (space_id),
                INDEX idx_opening_slot_establishment_weekday (establishment_id, weekday),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE opening_exception (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                space_id BINARY(16) DEFAULT NULL,
                exception_date DATE NOT NULL,
                type VARCHAR(32) NOT NULL,
                start_time TIME DEFAULT NULL,
                end_time TIME DEFAULT NULL,
                reason VARCHAR(160) NOT NULL,
                INDEX IDX_7524ACA38565851 (establishment_id),
                INDEX IDX_7524ACA323575340 (space_id),
                INDEX idx_opening_exception_establishment_date (establishment_id, exception_date),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE opening_setting (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                enforced TINYINT NOT NULL,
                UNIQUE INDEX uniq_opening_setting_establishment (establishment_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(
            'ALTER TABLE opening_slot ADD CONSTRAINT FK_opening_slot_etab '
            . 'FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)'
        );
        $this->addSql(
            'ALTER TABLE opening_slot ADD CONSTRAINT FK_opening_slot_espace '
            . 'FOREIGN KEY (space_id) REFERENCES acces_espace_acces (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE opening_exception ADD CONSTRAINT FK_opening_exception_etab '
            . 'FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)'
        );
        $this->addSql(
            'ALTER TABLE opening_exception ADD CONSTRAINT FK_opening_exception_espace '
            . 'FOREIGN KEY (space_id) REFERENCES acces_espace_acces (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE opening_setting ADD CONSTRAINT FK_opening_setting_etab '
            . 'FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE opening_slot');
        $this->addSql('DROP TABLE opening_exception');
        $this->addSql('DROP TABLE opening_setting');
    }
}
