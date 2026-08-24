<?php

declare(strict_types=1);

namespace App\Tests\Stay\Unit;

use App\Stay\Service\StayBalance;
use PHPUnit\Framework\TestCase;

/**
 * Arithmétique de la note (ACT-3). Ces tests portent sur des centimes, pas sur des flottants — et le
 * plus important d'entre eux est `testLeCentimeDeQuatreCinquanteNestPasPerdu`, qui fige le piège
 * classique : `(int) (4.50 * 100)` peut valoir 449.
 */
final class StayBalanceTest extends TestCase
{
    public function testUneNoteVideVautZero(): void
    {
        $solde = StayBalance::empty();

        self::assertTrue($solde->isZero());
        self::assertSame('0.00', $solde->total());
        self::assertSame(0, $solde->lineCount);
    }

    public function testLeCentimeDeQuatreCinquanteNestPasPerdu(): void
    {
        // 4.50 n'est pas représentable exactement en binaire : sans `round()`, le cast donne 449.
        self::assertSame(450, StayBalance::fromAmounts(['4.50'])->totalCents);
    }

    public function testAdditionDeTrenteLignesSansDerive(): void
    {
        $lignes = array_fill(0, 30, '0.10');

        $solde = StayBalance::fromAmounts($lignes);

        // En flottants, trente fois 0.10 donne 2.9999999999999996.
        self::assertSame('3.00', $solde->total());
        self::assertSame(30, $solde->lineCount);
    }

    public function testUneNoteRealisteSAdditionneJuste(): void
    {
        $solde = StayBalance::fromAmounts(['120.00', '9.00', '4.50', '17.80', '2.20']);

        self::assertSame('153.50', $solde->total());
        self::assertFalse($solde->isZero());
    }

    public function testUnAvoirNegatifSeDeduit(): void
    {
        // Un geste commercial ou une ligne annulée arrive en négatif : le solde doit baisser, pas
        // devenir invalide. C'est aussi ce qui permet d'annuler sans supprimer, donc sans perdre la trace.
        self::assertSame('91.00', StayBalance::fromAmounts(['120.00', '-29.00'])->total());
    }
}
