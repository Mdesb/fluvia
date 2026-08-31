<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agenda : ce qu'on note à la main, et l'abonnement ICS qui le publie.
 *
 * Deux tables, et c'est délibérément peu. L'agenda ne POSSÈDE pas ce qu'il montre : les créneaux de
 * réservation, les créneaux de travail et les plages d'ouverture restent chez leurs modules et sont
 * lus à la demande. Les recopier ici créerait une seconde vérité qui dériverait dès la première
 * annulation — un cours annulé la veille resterait affiché, et l'exploitant croirait le logiciel
 * plutôt que son planning.
 *
 * DDL relevé sur le mapping (D32), écrit à la main (jamais `migrations:diff`), horodaté en heure
 * locale — le conteneur PHP tourne en UTC et classerait cette migration avant des migrations déjà
 * appliquées.
 *
 * `ON DELETE CASCADE` sur l'utilisateur de l'abonnement : un jeton d'agenda qui survit à son
 * porteur est une URL qui rend des données au nom d'un compte qui n'existe plus.
 */
final class Version20260828140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agenda : événements saisis à la main et abonnement ICS nominatif.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE calendar_event (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                owner_id BINARY(16) DEFAULT NULL,
                title VARCHAR(160) NOT NULL,
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NOT NULL,
                all_day TINYINT NOT NULL,
                type VARCHAR(24) NOT NULL,
                notes LONGTEXT DEFAULT NULL,
                INDEX IDX_57FA09C98565851 (establishment_id),
                INDEX IDX_57FA09C97E3C61F9 (owner_id),
                INDEX idx_calendar_event_establishment_start (establishment_id, starts_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE calendar_ics_subscription (
                id BINARY(16) NOT NULL,
                user_id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                token VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_7A4A9243A76ED395 (user_id),
                INDEX IDX_7A4A92438565851 (establishment_id),
                UNIQUE INDEX uniq_calendar_ics_token (token),
                UNIQUE INDEX uniq_calendar_ics_user_establishment (user_id, establishment_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(
            'ALTER TABLE calendar_event ADD CONSTRAINT FK_calendar_event_etab '
            . 'FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)'
        );
        $this->addSql(
            'ALTER TABLE calendar_event ADD CONSTRAINT FK_calendar_event_owner '
            . 'FOREIGN KEY (owner_id) REFERENCES sec_utilisateur (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE calendar_ics_subscription ADD CONSTRAINT FK_calendar_ics_user '
            . 'FOREIGN KEY (user_id) REFERENCES sec_utilisateur (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE calendar_ics_subscription ADD CONSTRAINT FK_calendar_ics_establishment '
            . 'FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE calendar_event');
        $this->addSql('DROP TABLE calendar_ics_subscription');
    }
}
