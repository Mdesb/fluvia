<?php

declare(strict_types=1);

namespace App\Tests\Lodging\Unit;

use App\Lodging\Domain\LodgingPeriod;
use PHPUnit\Framework\TestCase;

/**
 * La nuitée (ACT-2, D16). Ces tests figent une règle qui vaut de l'argent : ce qu'occupe un séjour,
 * et ce qu'il n'occupe pas.
 */
final class LodgingPeriodTest extends TestCase
{
    private function periode(string $arrivee, string $depart): LodgingPeriod
    {
        return LodgingPeriod::fromDates(new \DateTimeImmutable($arrivee), new \DateTimeImmutable($depart));
    }

    public function testDuVingtQuatreAuVingtSeptFaitTroisNuits(): void
    {
        $periode = $this->periode('2026-08-24', '2026-08-27');

        // Trois, pas quatre. Compter le jour de depart ferait perdre une nuit vendable par sejour.
        self::assertSame(3, $periode->nightCount());
        self::assertSame(
            ['2026-08-24', '2026-08-25', '2026-08-26'],
            array_map(static fn (\DateTimeImmutable $n): string => $n->format('Y-m-d'), $periode->nights()),
        );
    }

    public function testLHeureDArriveeNeChangePasLeNombreDeNuits(): void
    {
        // Un client qui se presente a 22 h et repart a 9 h a dormi le meme nombre de nuits que celui
        // qui arrive a 15 h. Sans normalisation, le calcul dependrait de l heure du comptoir.
        $tardif = $this->periode('2026-08-24 22:30', '2026-08-27 09:00');

        self::assertSame(3, $tardif->nightCount());
    }

    public function testUneChambreRenduLeMatinEstRelouableLeSoir(): void
    {
        // **La regle qui vaut de l argent.** Un depart le 27 et une arrivee le 27 ne se chevauchent
        // pas ; les refuser condamnerait une chambre a rester vide un jour sur deux.
        $partant = $this->periode('2026-08-24', '2026-08-27');
        $arrivant = $this->periode('2026-08-27', '2026-08-30');

        self::assertFalse($partant->overlaps($arrivant));
        self::assertFalse($arrivant->overlaps($partant));
    }

    public function testUnVraiChevauchementResteRefuse(): void
    {
        $premier = $this->periode('2026-08-24', '2026-08-27');
        $second = $this->periode('2026-08-26', '2026-08-28');

        // La nuit du 26 est demandee deux fois : c est le cas ou deux clients recoivent la meme chambre.
        self::assertTrue($premier->overlaps($second));
        self::assertTrue($second->overlaps($premier));
    }

    public function testUnSejourSansNuitEstRefuse(): void
    {
        // Une chambre prise et rendue le meme jour est un « day use » : un creneau, pas une nuitee.
        // Les confondre ferait apparaitre au calendrier des sejours qui n occupent aucune nuit.
        $this->expectException(\InvalidArgumentException::class);
        $this->periode('2026-08-24', '2026-08-24');
    }

    public function testUnDepartAvantLArriveeEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->periode('2026-08-27', '2026-08-24');
    }

    public function testLeJourDeDepartNestPasUneNuitOccupee(): void
    {
        $periode = $this->periode('2026-08-24', '2026-08-27');

        self::assertTrue($periode->includesNight(new \DateTimeImmutable('2026-08-26')));
        self::assertFalse($periode->includesNight(new \DateTimeImmutable('2026-08-27')));
        self::assertFalse($periode->includesNight(new \DateTimeImmutable('2026-08-23')));
    }
}
