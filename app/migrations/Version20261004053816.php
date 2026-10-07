<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `updated_at` sur `acces_droit_acces` et `acces_support` (04/10/2026) : la date fiable de dernière
 * modification d'un droit et de sa projection, tenue par `AccessProjectionVersionListener` et
 * `SnapshotVersionBumper` — en même temps que `Support.versionMaj`, jamais sur une lecture.
 * L'API partenaire s'en servira pour `updatedSince` (décision de Maxime, « date fiable sur les droits »).
 *
 * **Valeur des lignes existantes : l'heure de la migration, en UTC.** La vraie date de leur dernière
 * modification n'existe nulle part (`synchronise_le` est un horodatage de synchronisation, pas de
 * modification). Les dater « maintenant » fait voir chaque droit une fois à un partenaire qui
 * interroge `updatedSince` — le sens sûr de l'erreur ; les dater dans le passé en ferait manquer.
 *
 * Écrite à la main (le SQL demandé à Doctrine par `schema:update --dump-sql` sur une base migrée,
 * sans la dérive des autres tables), en trois temps pour la colonne NOT NULL : ajout nullable,
 * remplissage, puis contrainte.
 */
final class Version20261004053816 extends AbstractMigration
{
    private const TABLES = [
        'acces_droit_acces' => 'idx_droit_acces_updated_at',
        'acces_support' => 'idx_support_updated_at',
    ];

    public function getDescription(): string
    {
        return 'Accès : updated_at (UTC) sur les droits et les supports, date fiable de modification';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => $index) {
            $this->addSql(sprintf('ALTER TABLE %s ADD updated_at DATETIME DEFAULT NULL', $table));
            $this->addSql(sprintf('UPDATE %s SET updated_at = UTC_TIMESTAMP()', $table));
            $this->addSql(sprintf('ALTER TABLE %s MODIFY updated_at DATETIME NOT NULL', $table));
            $this->addSql(sprintf('CREATE INDEX %s ON %s (updated_at)', $index, $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table => $index) {
            $this->addSql(sprintf('DROP INDEX %s ON %s', $index, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP updated_at', $table));
        }
    }
}
