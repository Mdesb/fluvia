<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;

/**
 * Catalogue (CA-1), actions de masse (CA-2) et cloisonnement établissement (RG-SOCLE-05).
 */
final class CatalogueTest extends OffreApiTestCase
{
    /** CA-1 — Recherche (libellé/code), filtres cumulables (type, statut) et tri des colonnes. */
    public function testCa1RechercheFiltresTri(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        // Recherche par libellé.
        $parLibelle = $client->request('GET', '/api/produits?libelleRecherche=Gold', $entete)->toArray();
        self::assertSame(1, $this->total($parLibelle));

        // Filtre par statut (les 3 produits des fixtures sont en brouillon).
        $parStatut = $client->request('GET', '/api/produits?statut=brouillon', $entete)->toArray();
        self::assertSame(3, $this->total($parStatut));

        // Filtre cumulable type + statut.
        $cumule = $client->request('GET', '/api/produits?statut=brouillon&typeCode=' . OffreFixtures::TYPE_CARTE, $entete)->toArray();
        self::assertSame(1, $this->total($cumule));

        // Tri par code croissant (colonne triable).
        $trie = $client->request('GET', '/api/produits?order[code]=asc', $entete)->toArray();
        $membres = $trie['member'] ?? $trie['hydra:member'];
        $codes = array_map(static fn (array $p): string => $p['code'], $membres);
        $triesAttendus = $codes;
        sort($triesAttendus);
        self::assertSame($triesAttendus, $codes, 'La liste doit être triable par code.');
    }

    /** CA-2 — Action de masse limitée à la sélection ; archivage (irréversible) exige confirmation. */
    public function testCa2ActionsDeMasse(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $idEntree = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);
        $idGold = $this->idProduit(OffreFixtures::PRODUIT_GOLD);
        $idCarte = $this->idProduit(OffreFixtures::PRODUIT_CARTE);

        // Archivage sans confirmation : refusé (422).
        $client->request('POST', '/api/produits/actions-de-masse', $entete + [
            'json' => ['action' => 'archiver', 'produits' => [$idEntree, $idGold]],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Archivage confirmé, uniquement sur la sélection (entrée + gold, pas la carte).
        $reponse = $client->request('POST', '/api/produits/actions-de-masse', $entete + [
            'json' => ['action' => 'archiver', 'produits' => [$idEntree, $idGold], 'confirmer' => true],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $reponse->toArray()['nbTraites']);

        $carte = $client->request('GET', '/api/produits/' . $idCarte, $entete)->toArray();
        self::assertSame('brouillon', $carte['statut'], 'La carte non sélectionnée doit rester en brouillon.');

        $gold = $client->request('GET', '/api/produits/' . $idGold, $entete)->toArray();
        self::assertSame('archive', $gold['statut']);
    }

    /** RG-SOCLE-05 — Un lecteur affecté à A ne voit pas un produit rattaché à B seulement. */
    public function testCloisonnementEtablissement(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $enteteAdmin = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        // L'admin (affecté à A et B) crée un produit rattaché à B uniquement.
        $idTypeEntree = $this->idType(OffreFixtures::TYPE_ENTREE);
        $client->request('POST', '/api/produits', $enteteAdmin + [
            'json' => [
                'libelle' => ['fr' => 'Produit exclusif B'],
                'type' => '/api/type_produits/' . $idTypeEntree,
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idB],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        // Le lecteur (affecté à A seulement) ne doit pas voir ce produit.
        $tokenLecteur = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $liste = $client->request('GET', '/api/produits', [
            'auth_bearer' => $tokenLecteur,
            'headers' => [ContexteEtablissement::HEADER => $idA],
        ])->toArray();
        $libelles = array_map(
            static fn (array $p): string => $p['libelle']['fr'] ?? '',
            $liste['member'] ?? $liste['hydra:member']
        );
        self::assertNotContains('Produit exclusif B', $libelles);
        self::assertContains(OffreFixtures::PRODUIT_ENTREE, $libelles, 'Le lecteur voit bien les produits de A.');
    }

    /** @param array<string, mixed> $reponse */
    private function total(array $reponse): int
    {
        return (int) ($reponse['totalItems'] ?? $reponse['hydra:totalItems'] ?? 0);
    }
}
