<?php

declare(strict_types=1);

namespace App\Tests\OptionProduit\Api;

use App\OptionProduit\Entity\ValeurOption;
use App\OptionProduit\Enum\ImpactOptionType;
use App\Tests\OptionProduit\OptionProduitApiTestCase;

/**
 * CA-6 / RG-OPT-09 — La sélection d'options sur une `LigneVente` est figée au moment de l'ajout
 * (libellé + impact) ; une évolution ultérieure du référentiel `GroupeOption`/`ValeurOption`
 * n'affecte pas rétroactivement les lignes déjà composées, même patron que `promotionsAppliquees`.
 * Référentiel construit via l'EntityManager (cf. `OptionProduitApiTestCase`) ; seule la route M2
 * déjà mappée `/ventes/{id}/lignes` est exercée en HTTP.
 */
final class SnapshotOptionsTest extends OptionProduitApiTestCase
{
    public function testSnapshotFigeNonRetroactifApresEvolutionReferentiel(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $groupe = $this->creerGroupeOption('Extra snapshot');
        $valeur = $this->creerValeurOption($groupe, 'Serviette', ImpactOptionType::Montant, '2.00');
        $this->creerOptionProduit($produit, $groupe);

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produit->getId(),
                'typeTarif' => '/api/type_tarifs/' . $tarif->getId(),
                'quantite' => 1,
                'options' => [(string) $valeur->getId()],
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        $ligne = $reponse['lignes'][0];
        self::assertSame('2.00', $ligne['impactOptionsUnitaire']);
        self::assertSame('22.00', $ligne['montantLigne']);
        self::assertSame('Serviette', $ligne['optionsSelectionnees'][0]['libelle']);
        self::assertSame('2.00', $ligne['optionsSelectionnees'][0]['montantUnitaireApplique']);

        // Évolution du référentiel : libellé et impact modifiés après coup (directement en base,
        // même geste métier qu'un PATCH API sur ValeurOption). Ré-attaquée depuis l'EntityManager
        // courant (le client de test reboote le noyau à chaque requête, cf. `alwaysBootKernel`) :
        // ré-emploi de l'objet `$valeur` d'un boot précédent laisserait `flush()` sans effet.
        $em = $this->em();
        $valeurFraiche = $em->getRepository(ValeurOption::class)->find($valeur->getId());
        self::assertInstanceOf(ValeurOption::class, $valeurFraiche);
        $valeurFraiche->setLibelle('Serviette XL')->setImpactValeur('9.00');
        $em->flush();

        // La ligne déjà composée n'est pas affectée rétroactivement (CA-6).
        $venteApres = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray();
        $ligneApres = $venteApres['lignes'][0];
        self::assertSame('Serviette', $ligneApres['optionsSelectionnees'][0]['libelle'], 'Snapshot figé : libellé non rétroactif.');
        self::assertSame('2.00', $ligneApres['optionsSelectionnees'][0]['montantUnitaireApplique'], 'Snapshot figé : impact non rétroactif.');
        self::assertSame('22.00', $ligneApres['montantLigne'], 'Montant de ligne inchangé.');

        // Une nouvelle sélection de la même valeur reflète en revanche le référentiel à jour.
        $venteBis = $this->creerVente($client, $entete, $session['id']);
        $reponseBis = $client->request('POST', '/api/ventes/' . $venteBis['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produit->getId(),
                'typeTarif' => '/api/type_tarifs/' . $tarif->getId(),
                'quantite' => 1,
                'options' => [(string) $valeur->getId()],
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        $ligneBis = $reponseBis['lignes'][0];
        self::assertSame('Serviette XL', $ligneBis['optionsSelectionnees'][0]['libelle']);
        self::assertSame('9.00', $ligneBis['optionsSelectionnees'][0]['montantUnitaireApplique']);
        self::assertSame('29.00', $ligneBis['montantLigne']);
    }
}
