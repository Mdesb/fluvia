<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;

/**
 * Remboursement / avoir / annulation par contre-passation (CA-13 / RG-M2-07) : droit requis,
 * traçabilité, avoir généré, support invalidé après impression, aucune ligne supprimée.
 */
final class ContrePassationTest extends VenteApiTestCase
{
    /** CA-13 — Annulation par un habilité : avoir + support invalidé (après impression) ; lignes conservées. */
    public function testCa13AnnulationGenereAvoirEtInvalideSupport(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $venteId = $this->venteCarteValidee($client, $entete, $session['id']); // 45,00, imprimée (>seuil)

        $avoir = $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + [
            'json' => ['motif' => 'Erreur de saisie'],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('annulee', $avoir['statutVente']);
        self::assertSame('annulation', $avoir['nature']);
        self::assertTrue($avoir['supportInvalide'], 'Annulation après impression → support invalidé (Accès).');
        self::assertSame('45.00', $avoir['montant']);

        // Aucune ligne d'origine supprimée (contre-passation).
        $vente = $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray();
        self::assertNotEmpty($vente['lignes']);
    }

    /** CA-13 — Remboursement partiel tracé : avoir « remboursement », vente en statut avoir_emis. */
    public function testCa13RemboursementPartiel(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $venteId = $this->venteCarteValidee($client, $entete, $session['id']);

        $avoir = $client->request('POST', '/api/ventes/' . $venteId . '/rembourser', $entete + [
            'json' => ['motif' => 'Geste commercial', 'montant' => '15.00'],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('remboursement', $avoir['nature']);
        self::assertSame('15.00', $avoir['montant']);
        self::assertSame('avoir_emis', $avoir['statutVente']);
    }

    /** CA-13 — Un opérateur non habilité (lecture seule) ne peut pas annuler (403). */
    public function testCa13NonHabiliteRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $venteId = $this->venteCarteValidee($client, $entete, $session['id']);

        // Lecteur (permission *.lire uniquement) sur l'établissement A.
        $lecteurClient = static::createClient();
        $token = $this->jeton($lecteurClient, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $enteteLecteur = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]];

        $lecteurClient->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteLecteur + [
            'json' => ['motif' => 'Test'],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function venteCarteValidee(object $client, array $entete, string $sessionId): string
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        return $vente['id'];
    }
}
