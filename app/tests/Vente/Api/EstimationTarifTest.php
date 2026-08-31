<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;

/**
 * **L'estimation doit donner le prix que la caisse facturera. Le même, pas un qui lui ressemble.**
 *
 * Maxime a signalé un ticket qui n'additionnait pas — « 1 × Test 10,00 € », total 15,00 €. La vente en
 * base était juste ; c'est l'écran qui mentait, parce qu'il retenait la **première grille vendable** du
 * produit là où le serveur applique le **tarif réellement dû**.
 *
 * Le test central de ce fichier n'est pas « l'estimation rend un prix » : c'est
 * `testLEstimationDonneExactementLePrixFacture`, qui **confronte l'estimation à la ligne réellement
 * créée**. C'est la seule assertion qui aurait attrapé le défaut d'origine — et c'est celle qu'on
 * n'écrit pas quand on teste chaque côté séparément, parce que chacun passe.
 */
final class EstimationTarifTest extends VenteApiTestCase
{
    /** **Le test qui porte la décision** : estimation et facturation rendent le même nombre. */
    public function testLEstimationDonneExactementLePrixFacture(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $estimation = $client->request('GET', $this->uriTarif(), $entete)->toArray();
        self::assertResponseIsSuccessful();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $ligne = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray()['lignes'][0];

        self::assertSame(
            $estimation['prixUnitaire'],
            $ligne['prixUnitaire'],
            'Le client entendrait un prix et en paierait un autre.',
        );
        self::assertSame($estimation['saison'], $ligne['saison'], 'La saison retenue doit être la même des deux côtés.');
    }

    /** Le prix vient avec sa raison — sinon le caissier annonce un chiffre qu'il ne sait pas défendre. */
    public function testLePrixVientAvecSonMotif(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $estimation = $client->request('GET', $this->uriTarif(), $entete)->toArray();
        self::assertResponseIsSuccessful();

        self::assertNotNull($estimation['prixUnitaire']);
        self::assertNotSame('', $estimation['motif']);
        self::assertStringContainsString(OffreFixtures::TARIF_PLEIN, $estimation['motif'], 'Le motif nomme le tarif appliqué.');
        self::assertSame('guichet', $estimation['canal']);
    }

    /** Un produit non commercialisé rend un prix nul **et dit pourquoi** — pas une erreur muette. */
    public function testUnProduitNonCommercialiseExpliqueLeRefus(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $estimation = $client->request('GET', $this->uriTarif(date: '2001-01-01'), $entete)->toArray();
        self::assertResponseIsSuccessful();

        self::assertNull($estimation['prixUnitaire']);
        self::assertStringContainsString('commercialisé', $estimation['motif']);
        self::assertSame([], $estimation['promotions'], 'Sans prix, aucune promotion ne s\'applique.');
    }

    /** Le paramètre obligatoire manquant est refusé, pas deviné. */
    public function testLeTypeTarifEstObligatoire(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('GET', '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE) . '/tarif', $entete);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Une date illisible est refusée plutôt qu'interprétée.
     *
     * Retomber sur « maintenant » rendrait un prix juste pour aujourd'hui à quelqu'un qui demandait
     * celui d'une autre saison — un résultat plausible et faux, le motif de la semaine.
     */
    public function testUneDateIllisibleEstRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('GET', $this->uriTarif(date: 'la semaine prochaine'), $entete);
        self::assertResponseStatusCodeSame(422);
    }

    /** L'estimation ne crée rien : aucune vente, aucune ligne, aucun mouvement de stock. */
    public function testLEstimationNeCreeRien(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $avant = $client->request('GET', '/api/ventes', $entete)->toArray()['totalItems'] ?? 0;
        $client->request('GET', $this->uriTarif(), $entete);
        self::assertResponseIsSuccessful();
        $apres = $client->request('GET', '/api/ventes', $entete)->toArray()['totalItems'] ?? 0;

        self::assertSame($avant, $apres);
    }

    private function uriTarif(?string $date = null): string
    {
        $parametres = ['typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN)];
        if ($date !== null) {
            $parametres['date'] = $date;
        }

        return '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE) . '/tarif?' . http_build_query($parametres);
    }
}
