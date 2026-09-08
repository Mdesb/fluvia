<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;

/**
 * LE TAUX DE TVA EST GRAVÉ SUR LA LIGNE, PAS RELU DU CATALOGUE.
 *
 * Même règle et même raison que `LibelleFigeTest` pour le nom du produit. Un taux légal change — la
 * restauration est passée de 19,6 % à 5,5 % puis à 10 %. Si la ventilation d'une vente se recalculait
 * depuis le catalogue d'aujourd'hui, tous les tickets déjà émis mentiraient rétroactivement, et un
 * duplicata tiré six mois plus tard annoncerait une TVA qui n'a jamais été collectée.
 *
 * ⚠ Le second test est celui qui compte autant : **l'absence de taux reste une absence**. Presque
 * aucun produit n'en porte aujourd'hui, et poser 20 % par défaut ferait porter au ticket
 * l'affirmation d'un taux que personne n'a choisi. Une ventilation incomplète se voit et se corrige ;
 * une ventilation fausse ne se voit pas.
 */
final class TauxTvaFigeTest extends VenteApiTestCase
{
    /** Le taux vendu ne bouge pas quand le catalogue change. */
    public function testLeTauxVenduSurvitAuChangementDuCatalogue(): void
    {
        [$client, $entete] = $this->adminSurA();
        $produitId = $this->idProduit(OffreFixtures::PRODUIT_CARTE);

        $this->poserTaux($client, $entete, $produitId, '10.00');

        $venteId = $this->venteAvecLigne($client, $entete, $produitId);
        $avant = $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray();
        self::assertSame('10.00', $avant['lignes'][0]['tauxTva'], 'Le taux du jour de la vente.');

        // Le catalogue change APRÈS la vente.
        $this->poserTaux($client, $entete, $produitId, '20.00');

        $apres = $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray();
        self::assertSame(
            '10.00',
            $apres['lignes'][0]['tauxTva'],
            'Une vente passee ne suit pas le catalogue : sinon tous les tickets emis mentent.',
        );
    }

    /** Sans taux au catalogue, la ligne n'en invente pas un. */
    public function testUnProduitSansTauxNeDonnePasUnTauxParDefaut(): void
    {
        [$client, $entete] = $this->adminSurA();
        $produitId = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);

        $venteId = $this->venteAvecLigne($client, $entete, $produitId);
        $vente = $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray();

        self::assertNull(
            $vente['lignes'][0]['tauxTva'],
            'Poser 20 % ferait porter au ticket un taux que personne n a choisi.',
        );
    }

    /**
     * ⚠ `$entete + [...]` NE MARCHE PAS ICI, et l'erreur est muette au premier regard.
     *
     * L'union de tableaux garde la clé de GAUCHE : `$entete` porte déjà `headers`, donc un
     * `'headers' => ['Content-Type' => ...]` ajouté à droite est purement ignoré. La requête part
     * alors en `application/ld+json` et l'opération `PATCH` rend 415. Les en-têtes se fusionnent,
     * ils ne s'ajoutent pas.
     *
     * @param array<string, mixed> $entete
     */
    private function poserTaux(object $client, array $entete, string $produitId, string $taux): void
    {
        $requete = $entete;
        $requete['headers'] = array_merge(
            \is_array($entete['headers'] ?? null) ? $entete['headers'] : [],
            ['Content-Type' => 'application/merge-patch+json'],
        );
        $requete['json'] = ['tauxTva' => $taux];

        $client->request('PATCH', '/api/produits/' . $produitId . '/compta', $requete);
        self::assertResponseIsSuccessful();
    }

    /** @param array<string, mixed> $entete */
    private function venteAvecLigne(object $client, array $entete, string $produitId): string
    {
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produitId,
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $vente['id'];
    }
}
