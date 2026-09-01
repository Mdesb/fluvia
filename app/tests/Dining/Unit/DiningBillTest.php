<?php

declare(strict_types=1);

namespace App\Tests\Dining\Unit;

use App\Dining\Domain\CourseRef;
use App\Dining\Domain\DiningBill;
use App\Dining\Domain\OrderLine;
use PHPUnit\Framework\TestCase;

/**
 * L'addition (ACT-4), et l'ecart entre ce que le client paie et ce que la cuisine a sorti.
 */
final class DiningBillTest extends TestCase
{
    private function ligne(string $libelle, int $quantite, string $prix): OrderLine
    {
        return new OrderLine(CourseRef::of('plat', 2), $libelle, $quantite, $prix);
    }

    private function midi(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-01 12:30:00');
    }

    public function testLeBrouillonNeCompteNiDunCoteNiDeLAutre(): void
    {
        $addition = new DiningBill([$this->ligne('Entrecote', 2, '24.50')]);

        // Rien n'est engage : ni du au client, ni sorti de la cuisine.
        self::assertSame('49.00', $addition->total(), 'Une ligne saisie figure a l\'addition en cours.');
        self::assertSame('0.00', $addition->consomme());
        self::assertSame('0.00', $addition->perte());
    }

    public function testUnPlatRenvoyeSortDeLAdditionEtResteEnPerte(): void
    {
        $servi = $this->ligne('Entrecote', 1, '24.50')->fire($this->midi());
        $renvoye = $this->ligne('Poisson', 1, '19.00')->fire($this->midi());
        $renvoye->void('Cuisson refusee par le client');

        $addition = new DiningBill([$servi, $renvoye]);

        // **Les deux totaux ne disent pas la meme chose**, et c'est tout l'interet : l'ecart est le
        // cout du service, comprehensible le soir meme au lieu d'etre decouvert a l'inventaire.
        self::assertSame('24.50', $addition->total());
        self::assertSame('43.50', $addition->consomme());
        self::assertSame('19.00', $addition->perte());
    }

    public function testLaQuantiteMultiplieBienLePrixUnitaire(): void
    {
        $addition = new DiningBill([$this->ligne('Demi', 3, '4.50')->fire($this->midi())]);

        // 3 x 4,50 : le piege du centime est ici, `(int) (4.50 * 100)` valant 449 sur certaines
        // plateformes.
        self::assertSame('13.50', $addition->total());
    }

    public function testTrenteDemisSansDeriveDeCentime(): void
    {
        $lignes = [];
        for ($i = 0; $i < 30; ++$i) {
            $lignes[] = $this->ligne('Demi', 1, '0.10')->fire($this->midi());
        }

        // En flottants, trente fois 0.10 donne 2.9999999999999996.
        self::assertSame('3.00', (new DiningBill($lignes))->total());
    }
}
