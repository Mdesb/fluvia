<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ACT-1 point 3 (D33) : les créneaux **consommés** par une réservation — le créneau visé, plus ceux
 * des ressources ancêtres qui le couvrent dans le temps. C'est ce qui rend exprimable « soixante
 * couverts sur le service de 20 h ».
 *
 * ⚠ Migration écrite à la main (D32). Le DDL n'est pas deviné : il est relevé sur la table que
 * Doctrine crée réellement depuis le mapping (`SHOW CREATE TABLE` sur une base de test montée par
 * `schema:create`), noms d'index et de contraintes compris — sinon le prochain `migrations:diff`
 * proposerait de les renommer. Horodatée en **heure locale** (22:12) et non en UTC : le conteneur
 * PHP est deux heures derrière, et une migration horodatée 20:12 se classerait avant des migrations
 * déjà appliquées ce soir.
 *
 * Une seule table créée, aucune table existante modifiée, aucun `DROP`.
 *
 * **Reprise de données, et elle est obligatoire.** `JaugeCreneauGuard` compte désormais par les
 * créneaux consommés, et `Reservation::setCreneau()` garantit que le créneau visé en fait partie —
 * mais seulement pour les réservations créées *après* ce lot. Sans la reprise ci-dessous, toutes les
 * réservations existantes deviendraient invisibles à la jauge : leurs créneaux passeraient pour
 * libres et se revendraient. C'est la même famille de défaut que celui corrigé une heure plus tôt
 * sur la promotion de liste d'attente, et il serait ici massif et immédiat.
 *
 * Seul le créneau **visé** est repris. Les créneaux ancêtres ne le sont pas : les recalculer
 * reviendrait à inventer rétroactivement une consommation qui n'a jamais été contrôlée à la
 * réservation, sur des créneaux pour la plupart passés.
 */
final class Version20260824221200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ACT-1 point 3 : table de liaison reservation_consumed_slot (créneaux consommés, D33).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reservation_consumed_slot (
                reservation_id BINARY(16) NOT NULL,
                creneau_id BINARY(16) NOT NULL,
                INDEX IDX_9B034A0CB83297E7 (reservation_id),
                INDEX IDX_9B034A0C7D0729A9 (creneau_id),
                PRIMARY KEY (reservation_id, creneau_id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE reservation_consumed_slot
                ADD CONSTRAINT FK_9B034A0CB83297E7 FOREIGN KEY (reservation_id)
                    REFERENCES reservation_reservation (id) ON DELETE CASCADE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE reservation_consumed_slot
                ADD CONSTRAINT FK_9B034A0C7D0729A9 FOREIGN KEY (creneau_id)
                    REFERENCES reservation_creneau (id) ON DELETE CASCADE
            SQL);

        // Reprise : chaque réservation existante consomme au moins son créneau visé (voir en-tête).
        // `INSERT IGNORE` plutôt qu'un `INSERT` sec — la migration doit rester rejouable sur une base
        // où la table aurait déjà été alimentée par un `schema:create`.
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO reservation_consumed_slot (reservation_id, creneau_id)
            SELECT id, creneau_id FROM reservation_reservation WHERE creneau_id IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reservation_consumed_slot');
    }
}
