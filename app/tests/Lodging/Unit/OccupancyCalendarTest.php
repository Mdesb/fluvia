<?php

declare(strict_types=1);

namespace App\Tests\Lodging\Unit;

use App\Lodging\Domain\LodgingPeriod;
use App\Lodging\Domain\OccupancyCalendar;
use App\Lodging\Domain\UnitOccupancy;
use PHPUnit\Framework\TestCase;

/**
 * Le calendrier d'occupation (ACT-2) : « j'ai une chambre double du 24 au 27, laquelle puis-je
 * donner ? »
 *
 * Trois chambres doubles, un mobil-home. Assez pour que « complet » et « il en reste une » soient
 * deux resultats distincts — un inventaire d une seule unite ferait passer au vert un calendrier qui
 * ne sait pas compter.
 */
final class OccupancyCalendarTest extends TestCase
{
    /** @var array<string, string> */
    private const PARC = [
        '101' => 'chambre_double',
        '102' => 'chambre_double',
        '103' => 'chambre_double',
        'MH1' => 'mobil_home',
    ];

    private function periode(string $arrivee, string $depart): LodgingPeriod
    {
        return LodgingPeriod::fromDates(new \DateTimeImmutable($arrivee), new \DateTimeImmutable($depart));
    }

    /** @param list<UnitOccupancy> $occupees */
    private function calendrier(array $occupees): OccupancyCalendar
    {
        return new OccupancyCalendar(self::PARC, $occupees);
    }

    public function testUnParcVideProposeLaPremiereUnite(): void
    {
        $calendrier = $this->calendrier([]);

        self::assertSame(['101', '102', '103'], $calendrier->freeUnitsFor('chambre_double', $this->periode('2026-08-24', '2026-08-27')));
        self::assertSame('101', $calendrier->firstAvailableUnitFor('chambre_double', $this->periode('2026-08-24', '2026-08-27')));
    }

    public function testUneUniteOccupeeNestPasProposee(): void
    {
        $calendrier = $this->calendrier([
            new UnitOccupancy('101', $this->periode('2026-08-23', '2026-08-26')),
        ]);

        self::assertSame(['102', '103'], $calendrier->freeUnitsFor('chambre_double', $this->periode('2026-08-24', '2026-08-27')));
    }

    public function testLaChambreRendueLeMatinEstProposeePourLeSoirMeme(): void
    {
        // **Le test qui vaut de l argent.** La 101 part le 27 ; elle doit etre proposable a qui
        // arrive le 27. La refuser condamnerait une chambre a rester vide un jour sur deux.
        $calendrier = $this->calendrier([
            new UnitOccupancy('101', $this->periode('2026-08-24', '2026-08-27')),
        ]);

        self::assertContains('101', $calendrier->freeUnitsFor('chambre_double', $this->periode('2026-08-27', '2026-08-30')));
        self::assertSame('101', $calendrier->firstAvailableUnitFor('chambre_double', $this->periode('2026-08-27', '2026-08-30')));
    }

    public function testCompletSeDitFranchement(): void
    {
        $sejour = $this->periode('2026-08-24', '2026-08-27');
        $calendrier = $this->calendrier([
            new UnitOccupancy('101', $sejour),
            new UnitOccupancy('102', $sejour),
            new UnitOccupancy('103', $sejour),
        ]);

        self::assertSame([], $calendrier->freeUnitsFor('chambre_double', $sejour));
        self::assertNull($calendrier->firstAvailableUnitFor('chambre_double', $sejour));
        self::assertFalse($calendrier->canAccommodate('chambre_double', $sejour));

        // Le mobil-home, lui, reste libre : un type complet n en condamne pas un autre.
        self::assertTrue($calendrier->canAccommodate('mobil_home', $sejour));
    }

    public function testUnTypeInconnuNaJamaisDeDisponibilite(): void
    {
        // Echec ferme : mieux vaut annoncer « rien » que proposer une unite d un autre type.
        self::assertFalse($this->calendrier([])->canAccommodate('yourte', $this->periode('2026-08-24', '2026-08-27')));
    }

    public function testLePlanningMuralCompteParNuit(): void
    {
        $calendrier = $this->calendrier([
            new UnitOccupancy('101', $this->periode('2026-08-24', '2026-08-26')),
            new UnitOccupancy('102', $this->periode('2026-08-25', '2026-08-27')),
        ]);

        // Le 24 : la 101 seule. Le 25 : les deux. Le 26 : la 102 seule, la 101 etant partie le matin.
        self::assertSame(
            [
                ['night' => '2026-08-24', 'occupied' => 1, 'total' => 3],
                ['night' => '2026-08-25', 'occupied' => 2, 'total' => 3],
                ['night' => '2026-08-26', 'occupied' => 1, 'total' => 3],
            ],
            $calendrier->occupancyByNight('chambre_double', $this->periode('2026-08-24', '2026-08-27')),
        );
    }

    public function testUneUniteDeuxFoisOccupeeNestComptabiliseeQuUneFois(): void
    {
        // Donnee incoherente (deux affectations qui se chevauchent, que Reservation refuse en 409) :
        // le planning ne doit pas afficher 4 occupees sur un parc de 3, ce qui rendrait le taux de
        // remplissage superieur a 100 %% et le tableau de bord absurde.
        $calendrier = $this->calendrier([
            new UnitOccupancy('101', $this->periode('2026-08-24', '2026-08-26')),
            new UnitOccupancy('101', $this->periode('2026-08-24', '2026-08-27')),
        ]);

        $planning = $calendrier->occupancyByNight('chambre_double', $this->periode('2026-08-24', '2026-08-25'));

        self::assertSame(1, $planning[0]['occupied']);
    }
}
