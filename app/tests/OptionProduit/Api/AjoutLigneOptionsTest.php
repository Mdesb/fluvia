<?php

declare(strict_types=1);

namespace App\Tests\OptionProduit\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\OptionProduit\Enum\ImpactOptionType;
use App\OptionProduit\Enum\ModeSelectionOption;
use App\Tests\OptionProduit\OptionProduitApiTestCase;

/**
 * Sélection d'options à l'ajout au panier (`POST /ventes/{id}/lignes`, corps enrichi `options`) :
 * impact tarifaire montant/pourcentage (RG-OPT-04, CA-3), obligatoire manquant (CA-4), choix unique
 * violé (CA-5), non-régression sans options, restriction établissement forcée par API (RG-OPT-07),
 * option désactivée (CA-9). Le référentiel (GroupeOption/ValeurOption/OptionProduit) est construit
 * directement via l'EntityManager (cf. `OptionProduitApiTestCase`) ; seule la route M2 déjà mappée
 * `/ventes/{id}/lignes` est exercée en HTTP.
 */
final class AjoutLigneOptionsTest extends OptionProduitApiTestCase
{
    /** RG-OPT-04 — Option facultative à impact montant fixe : prix ajusté (+2,00 €). */
    public function testOptionFacultativeMontantFixeAjustePrix(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $groupe = $this->creerGroupeOption('Extras');
        $valeur = $this->creerValeurOption($groupe, 'Supplément', ImpactOptionType::Montant, '2.00');
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
        self::assertSame('20.00', $ligne['prixUnitaire']);
        self::assertSame('2.00', $ligne['impactOptionsUnitaire']);
        self::assertSame('22.00', $ligne['montantLigne']);
        self::assertCount(1, $ligne['optionsSelectionnees']);
        self::assertSame('2.00', $ligne['optionsSelectionnees'][0]['montantUnitaireApplique']);
    }

    /** CA-3 — Cumul montant fixe (+2,00€) + pourcentage (+10%) sur base 20,00€ = 24,00€. */
    public function testCumulMontantFixeEtPourcentage24Euros(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $groupeA = $this->creerGroupeOption('Extra montant');
        $valeurA = $this->creerValeurOption($groupeA, 'Serviette', ImpactOptionType::Montant, '2.00');
        $this->creerOptionProduit($produit, $groupeA);

        $groupeB = $this->creerGroupeOption('Extra pourcentage');
        $valeurB = $this->creerValeurOption($groupeB, 'Majoration', ImpactOptionType::Pourcentage, '10.00');
        $this->creerOptionProduit($produit, $groupeB);

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produit->getId(),
                'typeTarif' => '/api/type_tarifs/' . $tarif->getId(),
                'quantite' => 1,
                'options' => [(string) $valeurA->getId(), (string) $valeurB->getId()],
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        $ligne = $reponse['lignes'][0];
        self::assertSame('4.00', $ligne['impactOptionsUnitaire']);
        self::assertSame('24.00', $ligne['montantLigne']);
    }

    /** CA-4 / RG-OPT-03 — Groupe obligatoire sans valeur sélectionnée : ajout refusé (422). */
    public function testOptionObligatoireManquanteRefuseAjout422(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $groupe = $this->creerGroupeOption('Taille', ModeSelectionOption::Unique);
        $this->creerValeurOption($groupe, 'S', ImpactOptionType::Montant, '0.00');
        $this->creerOptionProduit($produit, $groupe, obligatoire: true);

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produit->getId(),
                'typeTarif' => '/api/type_tarifs/' . $tarif->getId(),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('obligatoire', $client->getResponse()->getContent(false));
    }

    /** CA-5 / RG-OPT-05 — Deux valeurs envoyées pour un groupe à choix unique : rejeté (422). */
    public function testChoixUniqueDeuxValeursMemeGroupeRejete422(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $groupe = $this->creerGroupeOption('Taille bis', ModeSelectionOption::Unique);
        $valeurS = $this->creerValeurOption($groupe, 'S', ImpactOptionType::Montant, '0.00');
        $valeurM = $this->creerValeurOption($groupe, 'M', ImpactOptionType::Montant, '0.00');
        $this->creerOptionProduit($produit, $groupe);

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produit->getId(),
                'typeTarif' => '/api/type_tarifs/' . $tarif->getId(),
                'quantite' => 1,
                'options' => [(string) $valeurS->getId(), (string) $valeurM->getId()],
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** Non-régression (§2.1) — `options` absent : comportement strictement inchangé. */
    public function testOptionsAbsentesComportementInchange(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        $ligne = $reponse['lignes'][0];
        self::assertSame('5.50', $ligne['prixUnitaire']);
        self::assertSame('0.00', $ligne['impactOptionsUnitaire']);
        self::assertSame('4.95', $ligne['montantLigne'], 'Prix inchangé (promo -10% seule, aucune option).');
    }

    /** Cas limite §7 / RG-OPT-07 — Option hors établissement, sélection forcée par API : rejetée (422). */
    public function testOptionHorsEtablissementRejeteeSiForceeParApi(): void
    {
        [$client, $entete] = $this->adminSurA();
        $etabB = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $groupe = $this->creerGroupeOption('Restreinte');
        $valeur = $this->creerValeurOption($groupe, 'Casier B', ImpactOptionType::Montant, '1.00');
        $this->creerOptionProduit($produit, $groupe, etablissementsRestriction: [$etabB]);

        // Vente ouverte sur l'établissement A : l'option restreinte à B est hors périmètre.
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produit->getId(),
                'typeTarif' => '/api/type_tarifs/' . $tarif->getId(),
                'quantite' => 1,
                'options' => [(string) $valeur->getId()],
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** CA-9 (second volet) — Une valeur désactivée est refusée à un nouvel ajout. */
    public function testOptionDesactiveeRejeteeANouvelAjout(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $groupe = $this->creerGroupeOption('Extra désactivable');
        $valeur = $this->creerValeurOption($groupe, 'Extra', ImpactOptionType::Montant, '1.00');
        $this->creerOptionProduit($produit, $groupe);

        $valeur->setActif(false);
        $this->em()->flush();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produit->getId(),
                'typeTarif' => '/api/type_tarifs/' . $tarif->getId(),
                'quantite' => 1,
                'options' => [(string) $valeur->getId()],
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
