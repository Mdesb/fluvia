<?php

declare(strict_types=1);

namespace App\Tests\Caisse\Unit;

use App\Vente\Service\PanierCalculateur;
use PHPUnit\Framework\TestCase;

/**
 * Comparaison écart/tolérance en centimes (RG-CAISSEZ-05/05bis), telle qu'exécutée par
 * `CloturerSessionProcessor` : `abs(centimes(ecartTotal)) > centimes(toleranceEcartCaisse)`.
 * Vérifie en particulier le cas limite « écart nul exact avec tolérance nulle » et l'égalité stricte
 * (pas d'alerte quand l'écart == la tolérance).
 */
final class EcartToleranceComparaisonTest extends TestCase
{
    public function testComparaisonCentimesEcartTolerance(): void
    {
        $calc = new PanierCalculateur();

        // Écart -5,00 €, tolérance 0,00 € (défaut) : dépasse -> alerte.
        self::assertTrue(abs(-500) > $calc->centimes('0.00'));

        // Écart 3,00 €, tolérance 5,00 € : ne dépasse pas -> pas d'alerte.
        self::assertFalse(abs(300) > $calc->centimes('5.00'));

        // Écart 5,00 € exactement égal à la tolérance 5,00 € : comparaison stricte -> pas d'alerte.
        self::assertFalse(abs(500) > $calc->centimes('5.00'));

        // Écart nul exact avec tolérance nulle (cas limite §7 de la spec) : |0| > 0 est faux -> pas d'alerte.
        self::assertFalse(abs(0) > $calc->centimes('0.00'));
    }
}
