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

    /**
     * RG-SOCLE-05 — la grille tarifaire ne porte pas d'établissement : elle le tient de son produit.
     *
     * Sans jointure, la collection était lisible d'un établissement à l'autre : on lisait **les prix
     * pratiqués par un voisin**, tarif par tarif et saison par saison. Ce n'est pas de la
     * configuration partagée, c'est sa politique commerciale.
     */
    public function testLaGrilleTarifaireDunProduitHorsPerimetreNestPasListee(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $produit = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Produit a grille hors perimetre'],
                'type' => '/api/type_produits/' . $this->idType(OffreFixtures::TYPE_ENTREE),
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entite = $em->getRepository(\App\Offre\Entity\Produit::class)->find($produit['id']);
        self::assertNotNull($entite);

        $etabAEntite = $em->getRepository(\App\Organisation\Entity\Etablissement::class)->find($idA);
        self::assertNotNull($etabAEntite);
        $etranger = (new \App\Organisation\Entity\Etablissement())
            ->setNom('Etablissement hors perimetre ' . uniqid())
            ->setRegion($etabAEntite->getRegion());
        $em->persist($etranger);

        // La grille est creee AVANT le deplacement : on teste bien la lecture, pas l'ecriture.
        $grille = (new \App\Offre\Entity\GrilleTarifaire())->setProduit($entite)
            ->setTypeTarif($em->getRepository(\App\Offre\Entity\TypeTarif::class)->find($this->idTarif(OffreFixtures::TARIF_PLEIN)))
            ->setSaison($em->getRepository(\App\Offre\Entity\Saison::class)->find($this->idSaison(OffreFixtures::SAISON)))
            ->setPrix('42.00');
        $em->persist($grille);

        foreach ($entite->getEtablissements()->toArray() as $ancien) {
            $entite->getEtablissements()->removeElement($ancien);
        }
        $entite->getEtablissements()->add($etranger);
        $em->flush();

        $client->request('GET', '/api/grille_tarifaires', $entete + ['query' => ['itemsPerPage' => 100]]);
        self::assertResponseIsSuccessful();
        $membres = $client->getResponse()->toArray()['member'] ?? $client->getResponse()->toArray()['hydra:member'];
        $ids = array_map(static fn (array $g): string => $g['id'], $membres);
        self::assertNotContains((string) $grille->getId(), $ids, 'La grille d\'un produit hors perimetre ne doit pas etre listee.');
    }

    /**
     * D3/D8 — une action de masse ne doit pas atteindre le produit d'un établissement hors périmètre.
     *
     * Les identifiants viennent du corps de la requête, et l'action de masse en accepte une liste
     * entière : un `find()` sec permettait d'archiver — **irréversiblement** — le catalogue de
     * quelqu'un d'autre à qui savait deviner des UUID.
     *
     * Le produit est déplacé sur l'établissement C, du groupe voisin, où l'admin du groupe A n'a
     * aucune affectation (même montage que `testAdminNeVoitPasLesRessourcesDunAutreGroupe` côté
     * réservation). L'admin est affecté à A **et** à B : un test bâti sur B ne prouverait rien.
     */
    public function testActionDeMasseNAtteintPasLeProduitDunAutreGroupe(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $produit = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Produit deplace hors perimetre'],
                'type' => '/api/type_produits/' . $this->idType(OffreFixtures::TYPE_ENTREE),
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);

        // Bascule hors périmètre : commercialisé uniquement sur l'établissement C.
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entite = $em->getRepository(\App\Offre\Entity\Produit::class)->find($produit['id']);
        self::assertNotNull($entite);
        // Un établissement neuf, sur lequel l'admin n'a aucune affectation. Construit ici plutôt
        // que pris dans les fixtures : l'admin de démonstration est affecté à A **et** à B, donc
        // aucun des deux ne prouverait quoi que ce soit.
        $etabAEntite = $em->getRepository(\App\Organisation\Entity\Etablissement::class)->find($idA);
        self::assertNotNull($etabAEntite);
        $etabC = (new \App\Organisation\Entity\Etablissement())
            ->setNom('Etablissement hors perimetre ' . uniqid())
            ->setRegion($etabAEntite->getRegion());
        $em->persist($etabC);
        foreach ($entite->getEtablissements()->toArray() as $ancien) {
            $entite->getEtablissements()->removeElement($ancien);
        }
        $entite->getEtablissements()->add($etabC);
        $em->flush();
        $statutAvant = $entite->getStatut()->value;

        $reponse = $client->request('POST', '/api/produits/actions-de-masse', $entete + [
            'json' => ['action' => 'archiver', 'produits' => [$produit['id']], 'confirmer' => true],
        ])->toArray();

        // Même forme de réponse qu'un identifiant inexistant : pas d'oracle d'énumération.
        self::assertSame(0, $reponse['nbTraites']);
        self::assertSame('introuvable', $reponse['echecs'][0]['raison'] ?? null);

        // Et surtout : le produit n'a pas bougé. L'archivage est irréversible.
        $em->clear();
        $apres = $em->getRepository(\App\Offre\Entity\Produit::class)->find($produit['id']);
        self::assertNotNull($apres);
        self::assertSame($statutAvant, $apres->getStatut()->value, 'Aucune transition sur un produit hors périmètre.');
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
