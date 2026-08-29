<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le centre de notification : une notification est un événement qu'on a décidé de montrer.
 *
 * Demandé par Maxime — « il manque un centre de notification avec une petite cloche en haut à
 * droite ». La ressource est posée ici ; l'écran est tenu par allaccess-8e.
 *
 * ── UNE LIGNE PAR DESTINATAIRE, ET C'EST LE CHOIX STRUCTURANT ───────────────────────────────────
 *
 * Adresser à un rôle obligerait de toute façon à tenir l'état « lue » PAR UTILISATEUR — sinon le
 * premier qui lit efface la pastille des autres. On aurait donc deux tables et une jointure pour la
 * même information. La duplication est bornée par le critère d'admission : un événement ne devient
 * notification que s'il appelle le geste d'une personne et qu'il est rare.
 *
 * ── LES DEUX INDEX MÉTIER RÉPONDENT AUX DEUX SEULES QUESTIONS POSÉES ────────────────────────────
 *
 * `(destinataire_id, lue)` sert la pastille — « combien de non lues pour moi ». `(horodatage)` sert
 * la liste déroulante, toujours triée par date décroissante.
 *
 * ⚠ Sans tri déclaré et avec le plafond de pagination, la cloche montrerait les trente notifications
 * LES PLUS ANCIENNES : des lignes vraies, dans un ordre qui les rend inutiles. C'est le défaut
 * corrigé cette semaine sur les passages, et rien ne le signale.
 *
 * ── LES NOMS EN `IDX_…`/`FK_…` HACHÉS SONT CEUX DU MAPPING, ET C'EST DÉLIBÉRÉ ───────────────────
 *
 * ⚠ D32 dit « DDL relevé sur le mapping » — pas « DDL écrit dans le style qui me plaît ». Une
 * première version de cette migration nommait les index et les clés lisiblement
 * (`FK_notification_destinataire`). Résultat mesuré avec `doctrine:schema:update --dump-sql` : le
 * schéma se serait mis à diverger dès le premier jour, et cette table aurait rejoint la liste des
 * `RENAME INDEX` que ce dépôt traîne déjà sur une douzaine de tables.
 *
 * Les trois instructions ci-dessous sont donc **exactement** celles que le mapping produit, relevées
 * et non recopiées de mémoire. Les noms lisibles se paient une fois, la dérive se paie à chaque
 * comparaison de schéma.
 *
 * ── `ON DELETE CASCADE` SUR LE DESTINATAIRE, RIEN SUR L'ÉTABLISSEMENT ───────────────────────────
 *
 * Un utilisateur supprimé emporte ses notifications : elles ne s'adressent plus à personne, et les
 * garder serait conserver des données personnelles sans finalité. Un établissement ne se supprime
 * pas — la clé étrangère reste en RESTRICT implicite, et la base refuserait plutôt que d'orpheliner.
 */
final class Version20260830090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Centre de notification : une notification par destinataire, deux états.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_notification (
                id BINARY(16) NOT NULL,
                horodatage DATETIME NOT NULL,
                gravite VARCHAR(16) DEFAULT 'info' NOT NULL,
                titre VARCHAR(160) NOT NULL,
                texte LONGTEXT NOT NULL,
                ecran VARCHAR(64) NOT NULL,
                params JSON DEFAULT '{}' NOT NULL,
                source VARCHAR(64) NOT NULL,
                lue TINYINT DEFAULT 0 NOT NULL,
                lu_le DATETIME DEFAULT NULL,
                destinataire_id BINARY(16) NOT NULL,
                etablissement_id BINARY(16) NOT NULL,
                INDEX IDX_F7A9E787A4F84F6E (destinataire_id),
                INDEX IDX_F7A9E787FF631228 (etablissement_id),
                INDEX idx_notification_destinataire_lue (destinataire_id, lue),
                INDEX idx_notification_horodatage (horodatage),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql('ALTER TABLE platform_notification ADD CONSTRAINT FK_F7A9E787A4F84F6E FOREIGN KEY (destinataire_id) REFERENCES sec_utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE platform_notification ADD CONSTRAINT FK_F7A9E787FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_notification');
    }
}
