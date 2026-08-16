<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Unit;

use App\Reporting\Service\CalculComparaisonService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `CalculComparaisonService::calculerEcart` (§2.4 plan-reporting.md, M7-02) : écart valeur/%/couleur
 * bon/à surveiller/critique.
 */
final class CalculComparaisonServiceTest extends KernelTestCase
{
    public function testEcartPositifEstBon(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(CalculComparaisonService::class);

        $ecart = $service->calculerEcart('120.00', '100.00');

        self::assertSame('20.00', $ecart['ecartValeur']);
        self::assertSame('20.00', $ecart['ecartPourcentage']);
        self::assertSame('bon', $ecart['couleur']);
    }

    public function testEcartLegerementNegatifEstASurveiller(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(CalculComparaisonService::class);

        $ecart = $service->calculerEcart('95.00', '100.00');

        self::assertSame('-5.00', $ecart['ecartPourcentage']);
        self::assertSame('a_surveiller', $ecart['couleur']);
    }

    public function testEcartFortementNegatifEstCritique(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(CalculComparaisonService::class);

        $ecart = $service->calculerEcart('70.00', '100.00');

        self::assertSame('-30.00', $ecart['ecartPourcentage']);
        self::assertSame('critique', $ecart['couleur']);
    }

    public function testReferenceNulleNeDiviseJamaisParZero(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(CalculComparaisonService::class);

        $ecart = $service->calculerEcart('10.00', '0.00');

        self::assertNull($ecart['ecartPourcentage']);
        self::assertSame('inconnu', $ecart['couleur']);
    }
}
