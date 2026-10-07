<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fait avancer TOUS les supports d'une même version du snapshot terminal, pour que le filtre des
 * zones (D87) atteigne les bornes déjà synchronisées (06/10/2026).
 *
 * ⚠ SANS ELLE, LE CORRECTIF DU SNAPSHOT NE PARVIENT À AUCUNE BORNE EN DELTA. Une borne ne redemande
 * que les supports dont `version_maj` dépasse son curseur ; au déploiement, aucun n'a bougé. Elle
 * garde donc les `portesEligibles` calculés sans les zones — toutes les portes — jusqu'à son
 * prochain snapshot complet, dont la cadence n'est pas fixée. Mesuré en préprod le 06/10 : 34 droits
 * valides non exemptés sans zone, dont 27 sur un établissement à 3 terminaux : refusés en ligne,
 * ouverts hors ligne.
 *
 * UNE seule valeur pour tous (variable de session, pas `NEXT VALUE` dans le `SET`, qui serait tiré
 * ligne par ligne) : la pagination du snapshot départage les égalités par l'id. `updated_at` avance
 * avec : ce que les supports projettent a réellement changé.
 *
 * `down()` ne fait rien, délibérément : une version de snapshot ne recule jamais (le curseur des
 * bornes est monotone), et la faire reculer ferait manquer des modifications aux bornes.
 */
final class Version20261006103647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accès : avance la version snapshot de tous les supports (rattrapage du filtre des zones D87)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SET @acces_version_d87 = NEXT VALUE FOR acces_snapshot_seq');
        $this->addSql('UPDATE acces_support SET version_maj = @acces_version_d87, updated_at = UTC_TIMESTAMP()');
    }

    public function down(Schema $schema): void
    {
        // Rien : une version de snapshot ne recule jamais (voir le docbloc de classe).
    }
}
