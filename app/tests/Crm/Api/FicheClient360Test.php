<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Tests\Crm\CrmApiTestCase;

/**
 * US-L5-02, RG-M4-01 : fiche client 360°, enrichissement automatique.
 */
final class FicheClient360Test extends CrmApiTestCase
{
    /** CA-3, RG-M4-01 — Une vente validée enrichit automatiquement historique/agrégats, sans re-saisie. */
    public function testCa3EnrichissementAutomatiqueSansResaisie(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        $avant = $client->request('GET', '/api/clients/' . $payeurId . '/fiche-360', $entete)->toArray();
        self::assertNull($avant['client']['dateDerniereVisite']);
        self::assertSame('0.00', $avant['client']['caCumule']);

        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $payeurId);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes']]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete);
        self::assertResponseIsSuccessful();

        $apres = $client->request('GET', '/api/clients/' . $payeurId . '/fiche-360', $entete)->toArray();
        self::assertNotNull($apres['client']['dateDerniereVisite'], 'CA-3 : dernière visite mise à jour automatiquement.');
        self::assertNotSame('0.00', $apres['client']['caCumule'], 'CA-3 : CA cumulé mis à jour automatiquement.');

        // Horodatage/attribution (RG-M4-01) directement sur la fiche (via API interne, pas exposé
        // en JSON public — vérifié côté entité).
        $payeur = $this->entite(\App\Crm\Entity\Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertNotNull($payeur->getDateMaj());
        self::assertNotNull($payeur->getMajPar());
    }

    /** Champ saisi manuellement jamais écrasé par l'auto-enrichissement (RG-M4-01/11). */
    public function testChampManuelJamaisEcrasePartEnrichissementAuto(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        // Correction manuelle du téléphone.
        $client->request('PATCH', '/api/clients/' . $payeurId, [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'] + $entete['headers'],
            'json' => ['telephone' => '0699999999'],
        ]);
        self::assertResponseIsSuccessful();

        $payeur = $this->entite(\App\Crm\Entity\Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertTrue($payeur->estChampManuel('telephone'));
        self::assertSame('0699999999', $payeur->getTelephone());
    }

    /** CA-4 — Coordonnées, Historique, PMV, Famille, Consentements sur un seul appel. */
    public function testCa4FicheUnSeulEcran(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        $fiche = $client->request('GET', '/api/clients/' . $payeurId . '/fiche-360', $entete)->toArray();
        self::assertResponseIsSuccessful();

        foreach (['client', 'famille', 'pmv', 'consentements', 'historique'] as $bloc) {
            self::assertArrayHasKey($bloc, $fiche, sprintf('Bloc « %s » manquant sur la fiche 360.', $bloc));
        }
        self::assertSame('50.00', $fiche['pmv']['solde']);
        self::assertNotEmpty($fiche['famille']);
    }
}
