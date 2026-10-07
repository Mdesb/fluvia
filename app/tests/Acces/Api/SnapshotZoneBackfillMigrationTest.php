<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Enum\TypeDroitAcces;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261006103647;
use Psr\Log\NullLogger;

require_once \dirname(__DIR__, 3) . '/migrations/Version20261006103647.php';

/**
 * Le filtre des zones (D87) du snapshot doit ATTEINDRE les bornes déjà synchronisées.
 *
 * ⚠ CORRIGER LE CALCUL NE SUFFIT PAS. Une borne en delta ne redemande que les supports dont
 * `versionMaj` a avancé depuis son curseur. Le jour du déploiement, aucun support n'a bougé : la
 * borne garde les `portesEligibles` calculés par l'ancien code — toutes les portes, zones ignorées —
 * jusqu'à son prochain snapshot complet. Mesuré en préprod le 06/10 : 34 droits valides non exemptés
 * sans zone, dont 27 sur un établissement à 3 terminaux ; refusés en ligne, ouverts hors ligne.
 *
 * La migration fait donc avancer tous les supports d'une même version. Le test se place là où est
 * la borne : curseur pris AVANT, delta relu APRÈS.
 */
final class SnapshotZoneBackfillMigrationTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    public function testApresLaMigrationLeDeltaPorteLesPortesRecalculees(): void
    {
        [, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::Abonnement, 1, null, []);
        $porte = $this->idEquipement();
        $connexion = $this->snapshotEm()->getConnection();
        $connexion->executeStatement("UPDATE acces_support SET updated_at = '2020-01-01 00:00:00' WHERE identifiant = :i", ['i' => $identifiant]);

        // La borne s'est synchronisée en V, avant le déploiement du correctif.
        $curseur = $this->snapshotCursor();

        $this->jouerMigration();

        $entree = $this->assertInDelta($curseur, $identifiant, 'migration de rattrapage D87');
        self::assertNotContains($porte, $entree['portesEligibles'], 'D87 : zone vide = aucune porte, et la borne doit le recevoir.');
        self::assertGreaterThan(
            '2020-01-01 00:00:00',
            $this->snapshotEm()->getConnection()->fetchOne('SELECT updated_at FROM acces_support WHERE identifiant = :i', ['i' => $identifiant]),
            'updated_at avance avec la version.',
        );
    }

    private function jouerMigration(): void
    {
        $connexion = $this->snapshotEm()->getConnection();
        $migration = new Version20261006103647($connexion, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $requete) {
            $connexion->executeStatement($requete->getStatement(), $requete->getParameters(), $requete->getTypes());
        }
    }
}
