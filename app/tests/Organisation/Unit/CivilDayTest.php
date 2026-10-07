<?php

declare(strict_types=1);

namespace App\Tests\Organisation\Unit;

use App\Organisation\Entity\Etablissement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Etablissement::jourCivil()` : le jour de Paris, rendu comme une colonne `date` (00:00 dans le
 * fuseau du serveur). Après minuit à Paris, c'est déjà le lendemain, même s'il n'est que 22:30 ou
 * 23:30 UTC.
 */
final class CivilDayTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function instants(): iterable
    {
        yield 'hiver, 23:30 à Paris' => ['2026-12-31T22:30:00+00:00', '2026-12-31'];
        yield 'hiver, 00:30 à Paris le lendemain' => ['2026-12-31T23:30:00+00:00', '2027-01-01'];
        yield 'été, 23:30 à Paris' => ['2026-08-31T21:30:00+00:00', '2026-08-31'];
        yield 'été, 00:30 à Paris le lendemain' => ['2026-08-31T22:30:00+00:00', '2026-09-01'];
    }

    #[DataProvider('instants')]
    public function testCivilDayIsTheLocalDayAtServerMidnight(string $instant, string $jour): void
    {
        $attendu = new \DateTimeImmutable($jour);
        $paris = (new Etablissement())->setFuseauHoraire('Europe/Paris');

        self::assertEquals($attendu, Etablissement::jourCivil($paris, new \DateTimeImmutable($instant)));
        self::assertEquals($attendu, Etablissement::jourCivil(null, new \DateTimeImmutable($instant)), 'Sans établissement : Paris.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function fuseaux(): iterable
    {
        // [fuseau, jour local à 02:00 UTC le 01/01/2026]. Deux à l'ouest de Greenwich, deux à l'est.
        yield 'Martinique' => ['America/Martinique', '2025-12-31'];
        yield 'Guyane' => ['America/Cayenne', '2025-12-31'];
        yield 'Nouvelle-Calédonie' => ['Pacific/Noumea', '2026-01-01'];
        yield 'La Réunion' => ['Indian/Reunion', '2026-01-01'];
    }

    /**
     * Une date civile (« AAAA-MM-JJ », une colonne `date`, ce que rend `jourCivil()`) est déjà un
     * jour : elle reste ce jour-là dans tous les fuseaux. Un instant, lui, se lit à l'heure locale.
     *
     * Mesuré le 07/10/2026 : à l'ouest de Greenwich, 2026-01-01 (00:00 UTC) était relu comme le
     * 31/12/2025, 20:00 en Martinique.
     */
    #[DataProvider('fuseaux')]
    public function testACivilDateStaysTheSameDayInEveryTimeZone(string $fuseau, string $jourA2hUtc): void
    {
        $etablissement = (new Etablissement())->setFuseauHoraire($fuseau);
        $jour = new \DateTimeImmutable('2026-01-01');

        self::assertEquals($jour, Etablissement::jourCivil($etablissement, $jour));
        self::assertEquals($jour, Etablissement::jourCivil($etablissement, Etablissement::jourCivil($etablissement, $jour)));
        self::assertEquals(new \DateTimeImmutable($jourA2hUtc), Etablissement::jourCivil($etablissement, new \DateTimeImmutable('2026-01-01T02:00:00+00:00')));
    }
}
