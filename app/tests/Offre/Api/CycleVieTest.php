<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Categorie;
use App\Offre\Enum\AxeCategorie;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;

/**
 * Cycle de vie et opérations métier du produit : type verrouillé (CA-4), publication conditionnée
 * (CA-10/CA-11), dépublication (US-L1-09), conversion assistée (CA-13), duplication (CA-14).
 */
final class CycleVieTest extends OffreApiTestCase
{
    /** CA-4 / RG-M1-11 — Le champ « type » est verrouillé après création (PATCH ignoré). */
    public function testCa4TypeVerrouilleApresCreation(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $idEntree = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);
        $idTypeAbo = $this->idType(OffreFixtures::TYPE_ABONNEMENT);

        // Tentative de changer le type via PATCH : le champ est hors groupe d'écriture → ignoré.
        $reponse = $client->request('PATCH', '/api/produits/' . $idEntree, [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA, 'Content-Type' => 'application/merge-patch+json'],
            'json' => ['type' => '/api/type_produits/' . $idTypeAbo],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(OffreFixtures::TYPE_ENTREE, $reponse['type']['code'], 'Le type ne doit pas changer via PATCH.');
    }

    /** CA-10 / RG-M1-05 — Publication bloquée sans catégorie comptable ; autres axes facultatifs. */
    public function testCa10PublicationBloqueeSansCategorieComptable(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idTypeEntree = $this->idType(OffreFixtures::TYPE_ENTREE);

        // Produit complet SAUF catégorie comptable (aucune catégorie).
        $cree = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Sans compta'],
                'type' => '/api/type_produits/' . $idTypeEntree,
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();

        $client->request('POST', '/api/produits/' . $cree['id'] . '/publier', $entete);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('categorie_comptable', $client->getResponse()->getContent(false));
    }

    /** CA-11 / RG-M1-09 — Garde de publication complète, puis transitions autorisées. */
    public function testCa11PublicationGardeEtTransitions(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idTypeEntree = $this->idType(OffreFixtures::TYPE_ENTREE);

        // Produit incomplet : libellé + site OK, mais ni canal, ni prix, ni catégorie comptable.
        $incomplet = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Incomplet'],
                'type' => '/api/type_produits/' . $idTypeEntree,
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();
        $client->request('POST', '/api/produits/' . $incomplet['id'] . '/publier', $entete);
        self::assertResponseStatusCodeSame(422);
        $corps = $client->getResponse()->getContent(false);
        self::assertStringContainsString('canal', $corps);
        self::assertStringContainsString('prix', $corps);
        self::assertStringContainsString('categorie_comptable', $corps);

        // Produit complet (fixture) : publication OK, puis archivage OK.
        $idEntree = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);
        $publie = $client->request('POST', '/api/produits/' . $idEntree . '/publier', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('publie', $publie['statut']);

        $archive = $client->request('POST', '/api/produits/' . $idEntree . '/archiver', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('archive', $archive['statut']);
    }

    /** US-L1-09 — Dépublier un produit sans dépendance active le ramène en brouillon. */
    public function testDepublierSansDependance(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idEntree = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);

        $client->request('POST', '/api/produits/' . $idEntree . '/publier', $entete);
        self::assertResponseIsSuccessful();

        $depublie = $client->request('POST', '/api/produits/' . $idEntree . '/depublier', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('brouillon', $depublie['statut']);
    }

    /** CA-13 / RG-M1-11 — Conversion assistée : aperçu du mapping puis application + journal. */
    public function testCa13ConversionAssistee(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $idEntree = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);
        $idCarte = $this->idType(OffreFixtures::TYPE_CARTE);
        $idGold = $this->idProduit(OffreFixtures::PRODUIT_GOLD);
        $idEntreeType = $this->idType(OffreFixtures::TYPE_ENTREE);

        // Sans confirmation : aperçu du mapping, aucune modification (confirmationRequise).
        $apercu = $client->request('POST', '/api/produits/' . $idEntree . '/convertir', $entete + [
            'json' => ['nouveauType' => $idCarte, 'confirmer' => false],
        ])->toArray();
        self::assertTrue($apercu['confirmationRequise']);
        self::assertArrayHasKey('mapping', $apercu);

        // Avec confirmation : conversion appliquée.
        $converti = $client->request('POST', '/api/produits/' . $idEntree . '/convertir', $entete + [
            'json' => ['nouveauType' => $idCarte, 'confirmer' => true],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(OffreFixtures::TYPE_CARTE, $converti['type']['code']);

        // Le journal de conversion (append-only) contient l'opération.
        $journal = $client->request('GET', '/api/conversion_types', $entete)->toArray();
        self::assertGreaterThanOrEqual(1, $journal['totalItems'] ?? $journal['hydra:totalItems']);

        // Conversion vers un type incompatible : refusée (abonnement → entrée non compatible).
        $client->request('POST', '/api/produits/' . $idGold . '/convertir', $entete + [
            'json' => ['nouveauType' => $idEntreeType, 'confirmer' => true],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** CA-14 / US-L1-11 — Duplication : brouillon, code régénéré, libellé « – copie », tarifs repris. */
    public function testCa14Duplication(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $idGold = $this->idProduit(OffreFixtures::PRODUIT_GOLD);
        $original = $client->request('GET', '/api/produits/' . $idGold, $entete)->toArray();

        $copie = $client->request('POST', '/api/produits/' . $idGold . '/dupliquer', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('brouillon', $copie['statut']);
        self::assertNotSame($original['code'], $copie['code'], 'Le code doit être régénéré.');
        self::assertStringEndsWith('– copie', $copie['libelle']['fr']);
        self::assertNotEmpty($copie['grilles'], 'Les tarifs (grilles) doivent être repris.');
    }

    private function idCategorie(AxeCategorie $axe): string
    {
        return (string) $this->entite(Categorie::class, ['axe' => $axe])->getId();
    }
}
