<?php

declare(strict_types=1);

namespace App\Tests\OptionProduit\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\OptionProduit\Enum\ImpactOptionType;
use App\Tests\OptionProduit\OptionProduitApiTestCase;

/**
 * CA-10 / RG-OPT-10 — Une fois la vente scellée (NF525), le remboursement/l'annulation d'une ligne
 * portant des options reprend le montant total incluant leur impact tarifaire (aucun code de
 * `ContrePassationHandler` modifié : `Vente.getTotal()` agrège déjà `montantLigne`, §2.3/2.4 du plan).
 * Référentiel construit via l'EntityManager (cf. `OptionProduitApiTestCase`).
 */
final class ContrePassationOptionsTest extends OptionProduitApiTestCase
{
    /** CA-10 — L'avoir d'annulation reprend le montant de la ligne, options comprises. */
    public function testAvoirReprendMontantIncluantOptions(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idProduit = $this->idProduit(OffreFixtures::PRODUIT_CARTE); // 45,00 €, sans promo.
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_CARTE]);

        $groupe = $this->creerGroupeOption('Extra carte');
        $valeur = $this->creerValeurOption($groupe, 'Étui', ImpactOptionType::Montant, '5.00');
        $this->creerOptionProduit($produit, $groupe);

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $ligneReponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $idProduit,
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
                'options' => [(string) $valeur->getId()],
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        // 45,00 (carte) + 5,00 (étui) = 50,00.
        self::assertSame('50.00', $ligneReponse['lignes'][0]['montantLigne']);
        self::assertSame('50.00', $ligneReponse['total']);

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '50.00'],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $avoir = $client->request('POST', '/api/ventes/' . $vente['id'] . '/annuler', $entete + [
            'json' => ['motif' => 'Test options'],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('annulation', $avoir['nature']);
        self::assertSame('50.00', $avoir['montant'], 'L\'avoir reprend le montant total incluant les options (RG-OPT-10).');

        // Aucune ligne d'origine modifiée (contre-passation seule).
        $venteApres = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray();
        self::assertNotEmpty($venteApres['lignes'][0]['optionsSelectionnees']);
        self::assertSame('50.00', $venteApres['lignes'][0]['montantLigne']);
    }
}
