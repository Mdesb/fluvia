<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Unit;

use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Ressource;
use App\Reservation\Service\JaugeRessourceMereHandler;
use PHPUnit\Framework\TestCase;

/** Jauge de la ressource mère (RG-M5-08, CA-14). */
final class JaugeRessourceMereHandlerTest extends TestCase
{
    public function testDecrementSurAnnulationSousRessource(): void
    {
        $handler = new JaugeRessourceMereHandler();
        $etablissement = new Etablissement();

        $bassin = (new Ressource())->setEtablissement($etablissement)->setCodeType('bassin')->setLibelle('Bassin')
            ->setCapacitePropre(2)->setPartageable(true);
        $ligne1 = (new Ressource())->setEtablissement($etablissement)->setCodeType('ligne_eau')->setLibelle('Ligne 1')
            ->setCapacitePropre(6)->setRessourceMere($bassin);
        $ligne2 = (new Ressource())->setEtablissement($etablissement)->setCodeType('ligne_eau')->setLibelle('Ligne 2')
            ->setCapacitePropre(6)->setRessourceMere($bassin);

        self::assertFalse($handler->jaugeDepassee($ligne1));

        $handler->incrementer($ligne1);
        $handler->incrementer($ligne2);
        self::assertSame(2, $bassin->getOccupationCourante());
        self::assertTrue($handler->jaugeDepassee($ligne1), 'CA-14 : la jauge de la ressource mère est atteinte.');
        self::assertTrue($handler->jaugeDepassee($ligne2), 'La jauge mère est partagée par toutes les sous-ressources.');

        // Annulation d'une réservation sur la ligne 1 : décrémente la jauge mère (partagée).
        $handler->decrementer($ligne1);
        self::assertSame(1, $bassin->getOccupationCourante());
        self::assertFalse($handler->jaugeDepassee($ligne2));

        // Jamais négatif.
        $handler->decrementer($ligne1);
        $handler->decrementer($ligne1);
        self::assertSame(0, $bassin->getOccupationCourante());
    }
}
