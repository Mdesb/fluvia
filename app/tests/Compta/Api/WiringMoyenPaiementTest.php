<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Tests\Compta\ComptaApiTestCase;
use App\Vente\Port\ReferentielReglementInterface;

/**
 * §3 du plan (intégration L2 ↔ L4) : le référentiel des moyens de paiement M6 est visible depuis M2
 * sans modification du code M2 — seul l'alias `services.yaml` change (le stub reste en place).
 */
final class WiringMoyenPaiementTest extends ComptaApiTestCase
{
    public function testReferentielReglementResoutVersLAdaptateurM6(): void
    {
        $referentiel = static::getContainer()->get(ReferentielReglementInterface::class);
        self::assertInstanceOf(\App\Compta\Adapter\ReferentielReglementDoctrineAdapter::class, $referentiel);

        $moyens = $referentiel->moyensDisponibles();
        self::assertArrayHasKey('especes', $moyens);
        self::assertArrayHasKey('payfip', $moyens, 'Le moyen PayFiP seedé par M6 doit être visible depuis le port M2.');
        self::assertTrue($moyens['especes']->autoriseRendu);
    }

    public function testUneVenteM2PeutEncaisserAvecUnMoyenDuReferentielM6(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete, quantite: 1, moyen: 'especes');
        self::assertSame('validee', $vente['statut']);
    }
}
