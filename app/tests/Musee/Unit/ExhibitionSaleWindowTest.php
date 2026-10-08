<?php

declare(strict_types=1);

namespace App\Tests\Musee\Unit;

use App\Musee\Entity\Exposition;
use App\Organisation\Entity\Etablissement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les créneaux d'une exposition se proposent sur ses jours entiers, à l'heure de l'établissement.
 *
 * Mesuré le 07/10/2026 : `venteOuverteA()` prenait le jour UTC du début du créneau. Un créneau à
 * 00:30 à Paris le premier jour était refusé, et un créneau à 00:30 le lendemain du dernier jour
 * était accepté.
 */
final class ExhibitionSaleWindowTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function instants(): iterable
    {
        // Exposition du 01/10/2026 (heure d'été) au 31/12/2026 (heure d'hiver), à Paris.
        // [début du créneau en UTC, accepté].
        yield 'veille du premier jour à 23:30 à Paris' => ['2026-09-30T21:30:00+00:00', false];
        yield 'premier jour à 00:30 à Paris (22:30 UTC la veille)' => ['2026-09-30T22:30:00+00:00', true];
        yield 'dernier jour à 23:30 à Paris (22:30 UTC)' => ['2026-12-31T22:30:00+00:00', true];
        yield 'lendemain à 00:30 à Paris (23:30 UTC le dernier jour)' => ['2026-12-31T23:30:00+00:00', false];
    }

    #[DataProvider('instants')]
    public function testSlotsFollowTheLocalDay(string $instant, bool $accepte): void
    {
        $exposition = (new Exposition())->setEtablissement((new Etablissement())->setFuseauHoraire('Europe/Paris'))
            ->setDateDebut(new \DateTimeImmutable('2026-10-01'))->setDateFin(new \DateTimeImmutable('2026-12-31'));

        self::assertSame($accepte, $exposition->venteOuverteA(new \DateTimeImmutable($instant)));
    }
}
