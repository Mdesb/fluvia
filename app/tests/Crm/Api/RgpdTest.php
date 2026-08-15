<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Tests\Crm\CrmApiTestCase;

/**
 * US-L5-09, RG-M4-09 : droit à l'effacement / anonymisation.
 */
final class RgpdTest extends CrmApiTestCase
{
    /** CA-17, RG-M4-09 — Effacement : PII supprimées, statut=anonymise, historique agrégé conservé, aucune régression M2. */
    public function testCa17EffacementAnonymiseSansCasserVenteScellee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        // Vente scellée (NF525) rattachée au client, avant anonymisation.
        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $payeurId, 1);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes']]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete);
        self::assertResponseIsSuccessful();
        $venteId = $vente['id'];

        $demande = $client->request('POST', '/api/demande_r_g_p_ds', $entete + [
            'json' => ['client' => '/api/clients/' . $payeurId, 'type' => 'anonymisation'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $traitement = $client->request('POST', '/api/demandes-rgpd/' . $demande['id'] . '/traiter', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('realisee', $traitement['statut']);
        self::assertSame('anonymise', $traitement['clientStatut']);

        $payeurApres = $this->entite(Client::class, ['statut' => \App\Crm\Enum\StatutClient::Anonymise]);
        self::assertNull($payeurApres->getNom());
        self::assertNull($payeurApres->getEmail());
        self::assertNull($payeurApres->getTelephone());
        self::assertNotNull($payeurApres->getCaCumule(), 'CA-17 : historique agrégé (CA cumulé) conservé.');

        // Aucune régression M2 : la vente scellée est toujours consultable, référence UUID intacte.
        $venteApres = $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('validee', $venteApres['statut']);
        self::assertSame($payeurId, $venteApres['client'], 'CA-17 : Vente.client reste un UUID logique intact (pas de PII, pas de cascade).');
    }

    /** Verrouillage : une demande réalisée ne peut plus être traitée à nouveau. */
    public function testDemandeRealiseeEstVerrouillee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        $demande = $client->request('POST', '/api/demande_r_g_p_ds', $entete + [
            'json' => ['client' => '/api/clients/' . $payeurId, 'type' => 'anonymisation'],
        ])->toArray();
        $client->request('POST', '/api/demandes-rgpd/' . $demande['id'] . '/traiter', $entete);
        self::assertResponseIsSuccessful();

        $reponse = $client->request('POST', '/api/demandes-rgpd/' . $demande['id'] . '/traiter', $entete);
        self::assertSame(409, $reponse->getStatusCode(), 'Demande RGPD déjà réalisée : verrouillée.');
    }
}
