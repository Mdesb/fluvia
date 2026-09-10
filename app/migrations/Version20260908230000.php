<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Group — absorption musée (Phase B, approche « fidèle ») : provenance d'une réservation de
 * groupe qui est le miroir d'un `Musee\DossierGroupeScolaire`. Uuid NU (aucune FK vers Musee : la
 * dépendance ne va que Musee → App\Group) ; sert à l'idempotence de la migration de données.
 *
 * SQL relevé par `doctrine:schema:update --dump-sql` sur le mapping neuf.
 */
final class Version20260908230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'group_booking.source_musee_dossier_id : provenance du dossier musée migré (idempotence).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_booking ADD source_musee_dossier_id BINARY(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_booking DROP source_musee_dossier_id');
    }
}
