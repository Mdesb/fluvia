<?php

declare(strict_types=1);

namespace App\Tests\Lodging\Unit;

use App\Lodging\Domain\LodgingPeriod;
use App\Lodging\Domain\NightlyRates;
use PHPUnit\Framework\TestCase;

/**
 * Le tarif nuit par nuit (ACT-2, D16) — et l'arithmetique en centimes qui evite d afficher un total
 * faux d un centime sur une facture que le client relit.
 */
final class NightlyRatesTest extends TestCase
{
    private function periode(string $arrivee, string $depart): LodgingPeriod
    {
        return LodgingPeriod::fromDates(new \DateTimeImmutable($arrivee), new \DateTimeImmutable($depart));
    }

    public function testTroisNuitsAuTarifParDefaut(): void
    {
        $grille = new NightlyRates([], '80.00');

        self::assertSame('240.00', $grille->totalFor($this->periode('2026-08-24', '2026-08-27')));
    }

    public function testLaHauteSaisonSurchargeLaNuitConcernee(): void
    {
        // Le 14 juillet ne vaut pas un mardi de novembre : un sejour a cheval sur les deux ne peut
        // pas s exprimer par un montant unique multiplie.
        $grille = new NightlyRates(['2026-07-14' => '150.00'], '80.00');

        self::assertSame('310.00', $grille->totalFor($this->periode('2026-07-13', '2026-07-16')));
    }

    public function testLeJourDeDepartNestPasFacture(): void
    {
        $grille = new NightlyRates(['2026-08-27' => '999.00'], '80.00');

        // Le tarif du 27 existe mais la nuit du 27 n est pas occupee : il ne doit rien coûter.
        self::assertSame('240.00', $grille->totalFor($this->periode('2026-08-24', '2026-08-27')));
    }

    public function testLeDetailNuitParNuitEstOpposableAuClient(): void
    {
        $grille = new NightlyRates(['2026-08-25' => '95.50'], '80.00');

        self::assertSame(
            [
                ['night' => '2026-08-24', 'rate' => '80.00'],
                ['night' => '2026-08-25', 'rate' => '95.50'],
            ],
            $grille->breakdownFor($this->periode('2026-08-24', '2026-08-26')),
        );
    }

    public function testTrenteNuitsSansDeriveDeCentime(): void
    {
        // En flottants, trente fois 0.10 donne 2.9999999999999996.
        $grille = new NightlyRates([], '0.10');

        self::assertSame('3.00', $grille->totalFor($this->periode('2026-08-01', '2026-08-31')));
    }
}
