<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN PRODUIT CRÉÉ DEPUIS UN SITE NE DOIT PAS APPARAÎTRE DANS LE CATALOGUE DES AUTRES.
 *
 * `PerimetreProduitExtension` traite « aucun établissement » comme SOCLE : visible partout (D51,
 * « socle + ajout local »). C'est la bonne règle pour un catalogue fourni par l'éditeur, et la
 * mauvaise pour un produit qu'un exploitant vient de créer chez lui.
 *
 * Mesuré sur la préproduction avant d'écrire ce test : 17 produits, 12 établissements, 2 produits
 * rattachés. Les quinze autres — dont « Trésors d'Égypte » et « Entrée unitaire piscine » — sont
 * lisibles depuis une salle de sport.
 *
 * L'écran envoie déjà l'établissement actif à la création. Ce test ne vérifie donc pas l'écran : il
 * vérifie que le SERVEUR ne dépend pas de lui (D41). Un import, un script, ou l'écran avant que
 * l'établissement actif ne soit chargé publieraient le produit chez tous les clients — sans erreur,
 * sans trace, avec seulement des lignes en trop.
 */
final class ProduitRattachementTest extends OffreApiTestCase
{
    public function testUnProduitCreeSansEtablissementNAppartientQuAuSiteActif(): void
    {
        // `OffreApiTestCase::adminSurA()` rend un JETON, pas un en-tête tout fait — contrairement
        // au harnais Vente. Les deux en-têtes sont donc construits ici.
        [$client, $token, $idA] = $this->adminSurA();
        $enteteA = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        // Le corps omet volontairement `etablissements` : c'est le cas que le serveur doit couvrir.
        $cree = $client->request('POST', '/api/produits', $enteteA + [
            'json' => [
                'libelle' => ['fr' => 'Abonnement propre au site A'],
                'type' => '/api/type_produits/' . $this->idType(OffreFixtures::TYPE_ABONNEMENT),
                'canaux' => ['guichet'],
            ],
        ])->toArray();

        self::assertNotEmpty(
            $cree['etablissements'] ?? [],
            'le produit est resté sans établissement : il sera visible depuis tous les sites',
        );

        $enteteB = ['auth_bearer' => $token, 'headers' => [
            ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM),
        ]];

        self::assertContains('Abonnement propre au site A', $this->libelles($client, $enteteA));
        self::assertNotContains('Abonnement propre au site A', $this->libelles($client, $enteteB));
    }

    /**
     * LE SOCLE RESTE LE SOCLE.
     *
     * La correction ne doit pas emporter le catalogue partagé au passage : un produit créé SANS
     * établissement autrement que par l'API — ici directement en base, comme le fait un import ou
     * une reprise de données — reste visible des deux côtés.
     *
     * ⚠ Le produit socle est créé ICI, explicitement, et non emprunté aux fixtures : `OffreFixtures`
     * rattache TOUS ses produits à l'établissement A. Une première version de ce test comparait les
     * deux catalogues en supposant l'inverse — elle échouait en annonçant « le socle a disparu »
     * alors qu'il n'avait jamais existé dans le harnais. Un test doit poser lui-même ce qu'il
     * prétend mesurer.
     */
    public function testLeCatalogueSocleResteVisibleDesDeuxCotes(): void
    {
        $this->produitSocle('Catalogue éditeur partagé');

        [$client, $token, $idA] = $this->adminSurA();
        $enteteA = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $enteteB = ['auth_bearer' => $token, 'headers' => [
            ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM),
        ]];

        self::assertContains('Catalogue éditeur partagé', $this->libelles($client, $enteteA));
        self::assertContains('Catalogue éditeur partagé', $this->libelles($client, $enteteB));
    }

    private function produitSocle(string $libelle): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $type = $em->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ENTREE]);
        self::assertInstanceOf(TypeProduit::class, $type);

        $produit = (new Produit())
            ->setCode('SOCLE-TEST')
            ->setLibelle(['fr' => $libelle])
            ->setType($type);

        $em->persist($produit);
        $em->flush();
    }

    /** @param array<string, mixed> $entete @return list<string> */
    private function libelles(object $client, array $entete): array
    {
        $reponse = $client->request('GET', '/api/produits', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];

        return array_map(
            static function (array $produit): string {
                $libelle = $produit['libelle'] ?? '';

                return \is_array($libelle) ? (string) ($libelle['fr'] ?? '') : (string) $libelle;
            },
            $membres,
        );
    }
}
