<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;

/**
 * Tarification & référentiels : grille (CA-5), tranches de QF (CA-6), carte multi-entrées (CA-8),
 * référentiels non supprimables + unicité + non-chevauchement des saisons (CA-9).
 */
final class TarificationTest extends OffreApiTestCase
{
    /** CA-5 / RG-M1-01 — Case vide = non commercialisé, prix ≥ 0, historisation des prix. */
    public function testCa5GrilleNullPrixEtHistorisation(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idTypeEntree = $this->idType(OffreFixtures::TYPE_ENTREE);
        $idPlein = $this->idTarif(OffreFixtures::TARIF_PLEIN);
        $idGuichet = $this->idTarif(OffreFixtures::TARIF_GUICHET);
        $idSaison = $this->idSaison(OffreFixtures::SAISON);

        // Produit neuf sans grille.
        $produit = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Produit tarifé'],
                'type' => '/api/type_produits/' . $idTypeEntree,
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();

        // Case vide (prix null) : acceptée, vaut « non commercialisé ».
        $grille = $client->request('POST', '/api/grille_tarifaires', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produit['id'],
                'typeTarif' => '/api/type_tarifs/' . $idPlein,
                'saison' => '/api/saisons/' . $idSaison,
                'prix' => null,
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertNull($grille['prix']);

        // Prix négatif : refusé.
        $client->request('POST', '/api/grille_tarifaires', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produit['id'],
                'typeTarif' => '/api/type_tarifs/' . $idGuichet,
                'saison' => '/api/saisons/' . $idSaison,
                'prix' => '-5.00',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Historisation : deux modifications de prix → deux entrées d'historique conservées.
        $entetePatch = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA, 'Content-Type' => 'application/merge-patch+json'],
        ];
        $client->request('PATCH', '/api/grille_tarifaires/' . $grille['id'], $entetePatch + [
            'json' => ['prix' => '10.00'],
        ]);
        self::assertResponseIsSuccessful();
        $misAJour = $client->request('PATCH', '/api/grille_tarifaires/' . $grille['id'], $entetePatch + [
            'json' => ['prix' => '12.00'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertCount(2, $misAJour['historique'], 'Chaque modification de prix est historisée (append-only).');
    }

    /** CA-6 / US-L1-04 — Tranches de QF : refus des trous et chevauchements, contiguïté acceptée. */
    public function testCa6TranchesQuotientFamilial(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idPlein = $this->idTarif(OffreFixtures::TARIF_PLEIN);
        $iri = '/api/type_tarifs/' . $idPlein;

        // Première tranche [0, 600[ : acceptée.
        $client->request('POST', '/api/tranche_quotient_familials', $entete + [
            'json' => ['typeTarif' => $iri, 'borneMin' => '0', 'borneMax' => '600'],
        ]);
        self::assertResponseStatusCodeSame(201);

        // Tranche [700, 1200[ : trou entre 600 et 700 → refusée.
        $client->request('POST', '/api/tranche_quotient_familials', $entete + [
            'json' => ['typeTarif' => $iri, 'borneMin' => '700', 'borneMax' => '1200'],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Tranche [600, 1200[ : contiguë → acceptée.
        $client->request('POST', '/api/tranche_quotient_familials', $entete + [
            'json' => ['typeTarif' => $iri, 'borneMin' => '600', 'borneMax' => '1200'],
        ]);
        self::assertResponseStatusCodeSame(201);

        // Tranche [500, 800[ : chevauche les précédentes → refusée.
        $client->request('POST', '/api/tranche_quotient_familials', $entete + [
            'json' => ['typeTarif' => $iri, 'borneMin' => '500', 'borneMax' => '800'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** CA-8 / RG-M1-13 — Carte « 10=12 » : stock initial 12, crédité ≥ payé. */
    public function testCa8CarteMultiEntrees(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idTypeCarte = $this->idType(OffreFixtures::TYPE_CARTE);

        // 10 payées / 12 créditées : stock de compostages initial = 12.
        $carte = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Carte test'],
                'type' => '/api/type_produits/' . $idTypeCarte,
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
                'carte' => ['nbPaye' => 10, 'nbCredite' => 12],
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame(12, $carte['carte']['stockCompostagesInitial']);

        // Crédité < payé : refusé.
        $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Carte incohérente'],
                'type' => '/api/type_produits/' . $idTypeCarte,
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
                'carte' => ['nbPaye' => 10, 'nbCredite' => 5],
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** CA-9 / US-L1-07 — Référentiels : unicité, non-chevauchement des saisons, non-suppression si utilisé. */
    public function testCa9Referentiels(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        // Libellé unique : un type de tarif au nom déjà pris est refusé.
        $client->request('POST', '/api/type_tarifs', $entete + [
            'json' => ['nom' => OffreFixtures::TARIF_PLEIN, 'visibiliteCanal' => []],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Non-chevauchement : une saison de même priorité recouvrant la saison des fixtures est refusée.
        $client->request('POST', '/api/saisons', $entete + [
            'json' => [
                'nom' => 'Chevauchante',
                'dateDebut' => '2026-06-01',
                'dateFin' => '2026-09-30',
                'priorite' => 0,
            ],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Non-suppression : un type de tarif utilisé par une grille ne peut être supprimé (409).
        $idPlein = $this->idTarif(OffreFixtures::TARIF_PLEIN);
        $client->request('DELETE', '/api/type_tarifs/' . $idPlein, $entete);
        self::assertResponseStatusCodeSame(409);

        // Une saison utilisée ne peut être supprimée (409).
        $idSaison = $this->idSaison(OffreFixtures::SAISON);
        $client->request('DELETE', '/api/saisons/' . $idSaison, $entete);
        self::assertResponseStatusCodeSame(409);
    }
}
