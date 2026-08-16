<?php

declare(strict_types=1);

namespace App\Tests\Padel\Unit;

use App\Padel\Adapter\SimulateurEclairageAdapter;
use App\Padel\Entity\RelaisEclairageTerrain;
use App\Padel\Entity\TerrainPadel;
use App\Padel\Enum\ActionEclairage;
use App\Padel\Enum\StatutRelaisEclairage;
use PHPUnit\Framework\TestCase;

/** Adaptateur simulateur du pilotage éclairage (§2.2 du plan), CA-11. */
final class SimulateurEclairageAdapterTest extends TestCase
{
    public function testCommandeReussieEtJournalisee(): void
    {
        $adapter = new SimulateurEclairageAdapter();
        $relais = (new RelaisEclairageTerrain())->setTerrain(new TerrainPadel())->setIdentifiantRelais('R1');

        $resultat = $adapter->commander($relais, ActionEclairage::Allumage);

        self::assertTrue($resultat->ok);
        self::assertCount(1, $adapter->commandes());
        self::assertSame('allumage', $adapter->commandes()[0]['action']);
        self::assertSame(StatutRelaisEclairage::Operationnel, $adapter->heartbeat($relais));
    }

    public function testRelaisForceEnDefautRefuseLaCommande(): void
    {
        $adapter = new SimulateurEclairageAdapter();
        $relais = (new RelaisEclairageTerrain())->setTerrain(new TerrainPadel())->setIdentifiantRelais('R2');

        $adapter->forcerDefaut($relais);

        self::assertSame(StatutRelaisEclairage::EnDefaut, $adapter->heartbeat($relais));
        $resultat = $adapter->commander($relais, ActionEclairage::Extinction);
        self::assertFalse($resultat->ok);
    }
}
