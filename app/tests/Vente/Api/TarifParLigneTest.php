<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;

/**
 * D44 — le tarif est une propriété **de la ligne**, pas du panier.
 *
 * L'écran de guichet choisit un tarif unique pour toute la vente, ce qui oblige les exploitants à
 * créer « Entrée enfant » comme **produit** distinct au lieu d'une ligne tarifaire — un contournement
 * qui pollue le catalogue, fausse les statistiques par produit, et se paie ensuite à chaque
 * réconciliation.
 *
 * Ce test existe pour établir un fait, et un seul : **le serveur n'y est pour rien**. Il accepte déjà
 * un `typeTarif` par ligne et applique le prix correspondant. La contrainte est entièrement dans
 * l'écran, donc le correctif l'est aussi — c'est ce qu'il fallait vérifier avant de demander à
 * `claude-H` de toucher à `Caisse.jsx`.
 *
 * Deux lignes du **même produit** à deux tarifs différents, c'est exactement le cas « un adulte et un
 * enfant » qui motive D44.
 */
final class TarifParLigneTest extends VenteApiTestCase
{
    public function testDeuxLignesDuMemeProduitPeuventPorterDeuxTarifsDifferents(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $idProduit = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);

        // Plein tarif : 5,50 € dans la grille des fixtures.
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $idProduit,
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();

        // Tarif guichet sur LE MÊME produit, dans LE MÊME panier : 4,00 €.
        $apres = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $idProduit,
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_GUICHET),
                'quantite' => 1,
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $prix = array_map(
            static fn (array $ligne): string => $ligne['prixUnitaire'],
            $apres['lignes'],
        );
        sort($prix);

        self::assertSame(
            ['4.00', '5.50'],
            $prix,
            'Chaque ligne porte son propre tarif : le serveur ne contraint pas le panier a un tarif unique.'
        );
    }
}
