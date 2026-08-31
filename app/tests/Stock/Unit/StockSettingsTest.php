<?php

declare(strict_types=1);

namespace App\Tests\Stock\Unit;

use App\Stock\Entity\ParametrageStock;
use App\Stock\Enum\MethodeValorisation;
use App\Stock\Service\StockSettings;
use PHPUnit\Framework\TestCase;

/**
 * Le sens de « pas de paramétrage » (D52), figé une fois pour toutes.
 *
 * Ces tests ne vérifient pas un calcul, ils vérifient une **décision** : l'absence de configuration
 * n'accorde rien et ne désactive aucun contrôle. Ils existent parce que quatre lectures du module
 * improvisaient chacune leur repli, et que deux d'entre elles, dans le même fichier à vingt lignes
 * d'écart, tombaient dans des directions opposées.
 */
final class StockSettingsTest extends TestCase
{
    public function testSansParametrageLeStockNegatifNestPasAutorise(): void
    {
        // Une permission ne s'accorde pas par defaut.
        self::assertFalse(StockSettings::from(null)->allowsNegativeStock());
    }

    public function testSansParametrageToutEcartEstSignificatif(): void
    {
        // **Le defaut corrige.** Avant, l'absence rendait tout ecart insignifiant : le droit
        // `stock.valider_ecart` ne se declenchait jamais.
        self::assertTrue(StockSettings::from(null)->noThresholdConfigured());
    }

    public function testUnParametrageSansSeuilSeComporteCommeUneAbsence(): void
    {
        // Le cas le plus frequent en pratique : un exploitant cree son parametrage pour choisir sa
        // valorisation et ne remplit jamais les seuils. Les deux situations disent la meme chose.
        $parametrage = new ParametrageStock();

        self::assertNull($parametrage->getSeuilEcartSignificatifPourcentage());
        self::assertNull($parametrage->getSeuilEcartSignificatifMontant());
        self::assertTrue(StockSettings::from($parametrage)->noThresholdConfigured());
    }

    public function testUnSeulSeuilRenseigneSuffitAActiverLeControle(): void
    {
        $parametrage = (new ParametrageStock())->setSeuilEcartSignificatifPourcentage('8.00');

        self::assertFalse(StockSettings::from($parametrage)->noThresholdConfigured());
        self::assertSame('8.00', StockSettings::from($parametrage)->significanceThresholdPercentage());
    }

    public function testLaValorisationRetombeSurFifoEtCestLegitime(): void
    {
        // Ni permission ni controle : un choix technique, dont le defaut est celui de l'entite.
        self::assertSame(MethodeValorisation::Fifo, StockSettings::from(null)->valuationMethod());
    }

    public function testUnParametrageExpliciteEstRespecte(): void
    {
        $parametrage = (new ParametrageStock())->setAutoriserStockNegatif(true);

        // Ce qui a ete decide est applique : le repli ne s'impose qu'a defaut de decision.
        self::assertTrue(StockSettings::from($parametrage)->allowsNegativeStock());
    }
}
