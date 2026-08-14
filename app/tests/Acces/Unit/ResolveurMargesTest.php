<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Service\ResolveurMarges;
use PHPUnit\Framework\TestCase;

/**
 * Résolution des marges (§4.2 du plan) : intersection de la fenêtre du droit (élargie de ses marges
 * par défaut M1) et de la tolérance locale de l'équipement.
 */
final class ResolveurMargesTest extends TestCase
{
    public function testSansFenetreAucuneContrainte(): void
    {
        $resolveur = new ResolveurMarges();
        $droit = new DroitAcces();
        $equipement = new Equipement();

        self::assertTrue($resolveur->estDansMarges($droit, $equipement, new \DateTimeImmutable()));
    }

    public function testDansLaFenetreAvecMargesOk(): void
    {
        $resolveur = new ResolveurMarges();
        $debut = new \DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $fin = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');
        $droit = (new DroitAcces())->setFenetreDebut($debut)->setFenetreFin($fin)->setMargeAvanceDefaut(15)->setMargeRetardDefaut(15);
        $equipement = (new Equipement())->setMargeAvance(30)->setMargeRetard(30);

        // 10 min avant le début : dans la marge (intersection = min(15,30) = 15 min).
        self::assertTrue($resolveur->estDansMarges($droit, $equipement, $debut->modify('-10 minutes')));
    }

    public function testHorsFenetreEtMargesRefuse(): void
    {
        $resolveur = new ResolveurMarges();
        $debut = new \DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $fin = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');
        $droit = (new DroitAcces())->setFenetreDebut($debut)->setFenetreFin($fin)->setMargeAvanceDefaut(30)->setMargeRetardDefaut(30);
        $equipement = (new Equipement())->setMargeAvance(5)->setMargeRetard(5);

        // 20 min avant le début : l'équipement ne tolère que 5 min (intersection = min(30,5) = 5).
        self::assertFalse($resolveur->estDansMarges($droit, $equipement, $debut->modify('-20 minutes')));
    }
}
