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
}
