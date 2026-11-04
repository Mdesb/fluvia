<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN PRODUIT RATTACHÉ À PLUSIEURS SITES SE LIT, ET S'ENREGISTRE, DEPUIS N'IMPORTE LEQUEL.
 *
 * Mesuré le 03/11/2026 sur une pile de test : un produit rattaché à Piscine A et Patinoire B, lu
 * avec l'en-tête de A, ne rendait que A — la base avait les deux lignes. L'écran renvoyait alors
 * [A, B] en croyant AJOUTER B, et le serveur répondait 500 (`1062 Duplicate entry` sur
 * `off_produit_etablissement`). Décocher un site depuis la fiche était impossible pour la même
 * raison : le site n'était pas dans la liste lue.
 *
 * Cause : `PerimetreProduitExtension` choisissait les produits du site actif par une jointure sur
 * `etablissements` filtrée sur ce site, et l'item lu réutilisait cette jointure pour charger la
 * relation — la collection hydratée était la collection FILTRÉE.
 *
 * Le produit est rattaché ICI, en base, aux sites voulus : pas emprunté aux fixtures, qui ne
 * rattachent leurs produits qu'à A.
 */
final class ProductSitesTest extends OffreApiTestCase
{
    public function testLaLectureRendTousLesSitesDuProduit(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $idProduit = $this->produitSur('Produit des deux sites', [$idA, $idB]);

        foreach ([$idA, $idB] as $actif) {
            $lu = $client->request('GET', '/api/produits/' . $idProduit, $this->entete($token, $actif))->toArray();
            self::assertEqualsCanonicalizing(
                [$this->iri($idA), $this->iri($idB)],
                $this->sites($lu['etablissements'] ?? []),
                'lu depuis ' . $actif,
            );
        }
    }

    public function testRenvoyerLesDeuxSitesEnregistreSansErreur(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $idProduit = $this->produitSur('Produit renvoyé tel quel', [$idA, $idB]);

        $this->patch($client, $token, $idA, $idProduit, [$this->iri($idA), $this->iri($idB)]);

        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing([$idA, $idB], $this->sitesEnBase($idProduit));
    }

    public function testDecocherUnSiteLeRetire(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $idProduit = $this->produitSur('Produit décoché de B', [$idA, $idB]);

        $this->patch($client, $token, $idA, $idProduit, [$this->iri($idA)]);

        self::assertResponseIsSuccessful();
        self::assertSame([$idA], $this->sitesEnBase($idProduit));
    }

    /** Le cas à AUTORISER : sans lui, une garde qui refuserait tout ajout passerait les autres tests. */
    public function testAjouterUnSiteAtteignableReussit(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $idProduit = $this->produitSur('Produit étendu à B', [$idA]);

        $this->patch($client, $token, $idA, $idProduit, [$this->iri($idA), $this->iri($idB)]);

        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing([$idA, $idB], $this->sitesEnBase($idProduit));
    }

    public function testOnNeRattachePasUnProduitAUnSiteHorsDePortee(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $idEtranger = $this->siteEtranger();
        $idProduit = $this->produitSur('Produit poussé chez un autre client', [$idA]);

        $this->patch($client, $token, $idA, $idProduit, [$this->iri($idA), $this->iri($idEtranger)]);

        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
        self::assertLessThan(500, $client->getResponse()->getStatusCode());
        self::assertSame([$idA], $this->sitesEnBase($idProduit));
    }

    /**
     * Depuis que la lecture rend tous les sites, un appel peut en RETIRER un qu'il n'atteint pas.
     * Retirer un produit de la vente d'un site qu'on ne gère pas reste un geste de ce site-là.
     */
    public function testOnNeRetirePasUnProduitDUnSiteHorsDePortee(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $idEtranger = $this->siteEtranger();
        $idProduit = $this->produitSur('Produit partagé avec un site étranger', [$idA, $idEtranger]);

        $this->patch($client, $token, $idA, $idProduit, [$this->iri($idA)]);

        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
        self::assertLessThan(500, $client->getResponse()->getStatusCode());
        self::assertEqualsCanonicalizing([$idA, $idEtranger], $this->sitesEnBase($idProduit));
    }

    /** @param list<string> $sites */
    private function patch(object $client, string $token, string $actif, string $idProduit, array $sites): void
    {
        $client->request('PATCH', '/api/produits/' . $idProduit, $this->entete($token, $actif) + [
            'json' => ['etablissements' => $sites],
        ]);
    }

    /** @return array<string, mixed> */
    private function entete(string $token, string $actif): array
    {
        return ['auth_bearer' => $token, 'headers' => [
            ContexteEtablissement::HEADER => $actif,
            'Content-Type' => 'application/merge-patch+json',
        ]];
    }

    /** @param list<string> $sites */
    private function produitSur(string $libelle, array $sites): string
    {
        $em = $this->em();
        $type = $em->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ENTREE]);
        self::assertInstanceOf(TypeProduit::class, $type);

        $produit = (new Produit())
            ->setCode('SITES-' . substr(md5($libelle), 0, 8))
            ->setLibelle(['fr' => $libelle])
            ->setType($type);
        foreach ($sites as $id) {
            $produit->addEtablissement($em->getRepository(Etablissement::class)->find($id));
        }
        $em->persist($produit);
        $em->flush();
        $id = (string) $produit->getId();
        $em->clear();

        return $id;
    }

    /** Un groupe, une région, un site où personne des fixtures n'est affecté. */
    private function siteEtranger(): string
    {
        $em = $this->em();
        $groupe = (new Groupe())->setNom('Groupe étranger (sites du produit)');
        $region = (new Region())->setNom('Région étrangère (sites du produit)')->setGroupe($groupe);
        $site = (new Etablissement())->setNom('Site étranger (sites du produit)')->setRegion($region)->setActif(true);
        $em->persist($groupe);
        $em->persist($region);
        $em->persist($site);
        $em->flush();

        return (string) $site->getId();
    }

    /** @return list<string> lu en base, sans passer par l'API ni par son cloisonnement */
    private function sitesEnBase(string $idProduit): array
    {
        $em = $this->em();
        $em->clear();
        $produit = $em->getRepository(Produit::class)->find($idProduit);
        self::assertInstanceOf(Produit::class, $produit);

        return array_values(array_map(
            static fn (Etablissement $e): string => (string) $e->getId(),
            $produit->getEtablissements()->toArray(),
        ));
    }

    /** @param list<string|array<string, mixed>> $valeurs @return list<string> */
    private function sites(array $valeurs): array
    {
        return array_map(static fn ($v): string => \is_array($v) ? (string) ($v['@id'] ?? '') : (string) $v, $valeurs);
    }

    private function iri(string $id): string
    {
        return '/api/etablissements/' . $id;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
