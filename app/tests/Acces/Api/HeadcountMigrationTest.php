<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Tests\Acces\AccesApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261105114442;
use Psr\Log\NullLogger;

require_once \dirname(__DIR__, 3) . '/migrations/Version20261105114442.php';

/**
 * La migration du titre de groupe (#337), jouée dans les deux sens sur des lignes réelles.
 *
 * Le harnais monte le schéma depuis le mapping : un test vert ne dit rien de la migration. Ici on
 * joue `down()` puis `up()` sur la base des fixtures, et on vérifie que la colonne revient telle que
 * le mapping la décrit, avec 1 sur les droits existants — c'est-à-dire le comportement antérieur.
 */
final class HeadcountMigrationTest extends AccesApiTestCase
{
    public function testDownPuisUpRendLaColonneDuMappingAvecUnSurLesDroitsExistants(): void
    {
        self::bootKernel();
        /** @var Connection $connexion */
        $connexion = static::getContainer()->get('doctrine')->getConnection();
        $droits = (int) $connexion->fetchOne('SELECT COUNT(*) FROM acces_droit_acces');
        self::assertGreaterThan(0, $droits, 'Il faut des lignes pour que la migration prouve quelque chose.');
        $connexion->executeStatement('UPDATE acces_droit_acces SET headcount = 7');

        $this->jouer('down', $connexion);
        self::assertFalse($this->colonne($connexion), 'down() retire la colonne.');

        $this->jouer('up', $connexion);
        $colonne = $this->colonne($connexion);
        self::assertIsArray($colonne);
        self::assertSame('NO', $colonne['Null']);
        self::assertSame('1', (string) $colonne['Default']);
        self::assertStringStartsWith('int', strtolower((string) $colonne['Type']));
        self::assertSame(
            [['headcount' => 1, 'n' => $droits]],
            array_map(static fn (array $l): array => ['headcount' => (int) $l['headcount'], 'n' => (int) $l['n']], $connexion->fetchAllAssociative('SELECT headcount, COUNT(*) AS n FROM acces_droit_acces GROUP BY headcount')),
            'Après up(), chaque droit existant vaut 1 : un titre, une personne.',
        );
    }

    private function jouer(string $sens, Connection $connexion): void
    {
        /** @var AbstractMigration $migration */
        $migration = new Version20261105114442($connexion, new NullLogger());
        $migration->{$sens}(new Schema());
        foreach ($migration->getSql() as $requete) {
            $connexion->executeStatement($requete->getStatement(), $requete->getParameters(), $requete->getTypes());
        }
    }

    /** @return array<string, mixed>|false */
    private function colonne(Connection $connexion): array|false
    {
        return $connexion->fetchAssociative("SHOW COLUMNS FROM acces_droit_acces LIKE 'headcount'");
    }
}
