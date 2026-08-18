<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Tests\Facturation\FacturationApiTestCase;

/**
 * US-FACT-06, RG-FACT-07 (CA-9) : dépôt Chorus Pro pour un destinataire personne morale de droit
 * public — `FactureB2G` créée/liée, statut d'envoi tracé, rejeu sans nouveau numéro en cas d'échec.
 */
final class ChorusProFactureApiTest extends FacturationApiTestCase
{
    public function testCa9DepotB2gTraceStatutEnvoi(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => [
                'destinataire' => [
                    'type' => 'personne_morale',
                    'raisonSociale' => 'Mairie de Test',
                    'siret' => '12345678900011',
                    'adresse' => ['rue' => '1 place de la Mairie', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
                    'estOrganismePublic' => true,
                ],
                'lignes' => [[
                    'designation' => 'Prestation collectivité',
                    'quantite' => 1,
                    'prixUnitaireHT' => '500.00',
                    'tauxTva' => '/api/taux_tvas/' . $this->idTauxTva('Taux normal 20 %'),
                ]],
            ],
        ])->toArray();
        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();
        self::assertNull($emise['factureB2G'] ?? null);

        $b2g = $client->request('POST', '/api/factures/' . $emise['id'] . '/chorus', $entete + [
            'json' => ['numeroEngagement' => 'ENG-2026-001', 'serviceExecutant' => 'Service Sport'],
        ]);
        self::assertSame(201, $b2g->getStatusCode());
        $donneesB2g = $b2g->toArray();
        self::assertSame('transmis', $donneesB2g['statutEnvoi']);
        self::assertSame('ENG-2026-001', $donneesB2g['numeroEngagement']);

        $factureApres = $client->request('GET', '/api/factures/' . $emise['id'], $entete)->toArray();
        self::assertSame('chorus_pro', $factureApres['canal']);
        self::assertNotNull($factureApres['factureB2G']);
        $numeroAvantRejeu = $factureApres['numero'];

        // Rejeu du dépôt (cas limite « échec de dépôt Chorus Pro », §7 spec) : pas de nouveau numéro.
        $rejeu = $client->request('POST', '/api/factures/' . $emise['id'] . '/chorus', $entete)->toArray();
        self::assertSame($donneesB2g['id'], $rejeu['id'], 'Même enregistrement FactureB2G réutilisé.');

        $factureFinale = $client->request('GET', '/api/factures/' . $emise['id'], $entete)->toArray();
        self::assertSame($numeroAvantRejeu, $factureFinale['numero']);
    }
}
