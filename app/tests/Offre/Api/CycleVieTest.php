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

    /**
     * CA-10 / RG-M1-05 — Publication bloquée sans catégorie comptable.
     *
     * ⚠ **Ce test a changé de type de produit le 27/08, et il fallait qu'il change.**
     *
     * ACT-5 remplit désormais l'axe comptable depuis `TypeProduit::$defauts`, et les trois types du
     * jeu de démonstration en portent un. Sur `TYPE_ENTREE`, la catégorie n'est donc plus manquante —
     * ce test passait pour une raison qui a cessé d'exister.
     *
     * Le laisser sur un type outillé aurait été le pire des deux mondes : il aurait échoué, on
     * l'aurait « réparé » en changeant l'assertion, et **la garde de publication n'aurait plus été
     * testée nulle part**. Elle l'est ici, sur un type délibérément dépourvu de défauts.
     *
     * > **Un test qu'on adapte à un changement doit continuer de prouver la même chose, sinon on a
     * > seulement supprimé la preuve.**
     */
    public function testCa10PublicationBloqueeSansCategorieComptable(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idTypeNu = $this->idTypeSansDefauts();

        // Produit complet SAUF catégorie comptable, sur un type qui n'en propose aucune.
        $cree = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Sans compta'],
                'type' => '/api/type_produits/' . $idTypeNu,
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();

        $client->request('POST', '/api/produits/' . $cree['id'] . '/publier', $entete);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('categorie_comptable', $client->getResponse()->getContent(false));
    }

    /**
     * ACT-5 — sur un type OUTILLÉ, l'axe comptable ne manque plus.
     *
     * Le pendant du test précédent, et il ne se déduit pas de lui : le premier prouve que la garde
     * refuse, celui-ci prouve que l'automatisme **a effectivement rempli quelque chose**. Sans lui,
     * un `appliquer()` qui ne ferait rien passerait inaperçu — le produit resterait bloqué, et on
     * conclurait que la garde fonctionne bien.
     */
    public function testAct5LAxeComptableNeManquePlusSurUnTypeOutille(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idTypeEntree = $this->idType(OffreFixtures::TYPE_ENTREE);

        $cree = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Compta automatique'],
                'type' => '/api/type_produits/' . $idTypeEntree,
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();

        $client->request('POST', '/api/produits/' . $cree['id'] . '/publier', $entete);
        $corps = $client->getResponse()->getContent(false);

        self::assertStringNotContainsString(
            'categorie_comptable',
            $corps,
            'ACT-5 : la categorie comptable du type doit avoir ete posee a la creation.',
        );
        // Le prix, lui, manque toujours : l'automatisme ne remplit QUE les catégories, et le dire
        // évite qu'on croie plus tard qu'il fait davantage.
        self::assertStringContainsString('prix', $corps);
    }

    /** Un type de produit sans défauts de catégories — pour tester la garde, et rien d'autre. */
    private function idTypeSansDefauts(): string
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $type = (new \App\Offre\Entity\TypeProduit())
            ->setCode('sans_defauts_' . bin2hex(random_bytes(3)))
            ->setLibelle('Type sans defauts');
        $em->persist($type);
        $em->flush();

        return (string) $type->getId();
    }

    /** CA-11 / RG-M1-09 — Garde de publication complète, puis transitions autorisées. */
    public function testCa11PublicationGardeEtTransitions(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idTypeEntree = $this->idType(OffreFixtures::TYPE_ENTREE);

        // Produit incomplet : libellé + site OK, mais ni canal, ni prix, ni catégorie comptable.
        //
        // ⚠ Sur un type SANS défauts : depuis ACT-5, `TYPE_ENTREE` remplit l'axe comptable tout seul,
        // et la garde n'aurait plus eu les trois prérequis à énumérer. Le test vérifie que le message
        // les nomme TOUS — c'est son objet, et il n'aurait plus rien vérifié avec deux sur trois.
        $incomplet = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Incomplet'],
                'type' => '/api/type_produits/' . $this->idTypeSansDefauts(),
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();
        $client->request('POST', '/api/produits/' . $incomplet['id'] . '/publier', $entete);
        self::assertResponseStatusCodeSame(422);
        $corps = $client->getResponse()->getContent(false);
        self::assertStringContainsString('canal', $corps);
        self::assertStringContainsString('prix', $corps);
        self::assertStringContainsString('categorie_comptable', $corps);

        // Produit complet (fixture) : publication OK, puis archivage OK. Il part d'un brouillon.
        $this->remettreEnBrouillon(OffreFixtures::PRODUIT_ENTREE);
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
        $this->remettreEnBrouillon(OffreFixtures::PRODUIT_ENTREE);
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
