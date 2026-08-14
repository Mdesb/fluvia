<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;

/**
 * NF525 côté API (CA-15) : chaque opération validée est scellée & chaînée ; la vérification de chaîne
 * remonte l'intégrité ; une vente validée est figée (panier non modifiable = immuabilité applicative).
 */
final class Nf525ApiTest extends VenteApiTestCase
{
    /** CA-15 — Opérations scellées & chaînées ; verifieChaine intacte ; vente validée figée. */
    public function testCa15ChainageEtImmuabilite(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        // Deux ventes validées → deux maillons dans la chaîne du point de vente.
        $vente1 = $this->venteCarteValidee($client, $entete, $session['id']);
        $this->venteCarteValidee($client, $entete, $session['id']);

        // Chaque opération scellée expose numéro de séquence + empreinte + signature.
        $ops = $client->request('GET', '/api/operation_scellees', $entete)->toArray();
        $membres = $ops['member'] ?? $ops['hydra:member'] ?? [];
        self::assertGreaterThanOrEqual(2, \count($membres));
        self::assertArrayHasKey('numeroSequence', $membres[0]);
        self::assertArrayHasKey('empreinte', $membres[0]);
        self::assertArrayHasKey('signature', $membres[0]);

        // Vérification de chaîne : intacte.
        $rapport = $client->request('POST', '/api/nf525/verifier-chaine', $entete + [
            'json' => ['pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente()],
        ])->toArray();
        self::assertTrue($rapport['intacte']);
        self::assertGreaterThanOrEqual(2, $rapport['nbOperations']);
        self::assertEmpty($rapport['anomalies']);

        // Immuabilité : impossible d'ajouter une ligne à une vente validée (panier figé).
        $client->request('POST', '/api/ventes/' . $vente1 . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseStatusCodeSame(409);
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
