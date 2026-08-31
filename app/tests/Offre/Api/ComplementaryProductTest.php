<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;

/**
 * Les compléments d'un produit — « le casier avec l'entrée » (remplace `produitsAssocies`).
 *
 * ── POURQUOI CE FICHIER EXISTE AU MOMENT OÙ L'API S'OUVRE ───────────────────────────────────────
 *
 * `ComplementaryProduct` était posée depuis un lot précédent, sans opérations d'API : le
 * cloisonnement ne s'exécutait donc jamais sur elle, et sa chaîne de périmètre était fausse —
 * `PerimetreProduitExtension` la déclarait sous `'produit'` quand la propriété se nomme `$product`.
 *
 * ⚠ Une fuite de cloisonnement ne produit pas d'erreur, elle produit des lignes en trop. Le test
 * ci-dessous est donc écrit le jour même de l'ouverture, et pas après.
 */
final class ComplementaryProductTest extends OffreApiTestCase
{
    /**
     * ⚠ UN COMPLÉMENT SE POSE, SE LIT, ET SE RETIRE.
     *
     * Le cycle entier, parce que c'est ce qui manquait à `produitsAssocies` : un ManyToMany avec un
     * `add` et sans `remove`. On pouvait en ajouter, jamais en retirer — et rien ne lisait le champ
     * côté serveur, donc l'écran écrivait dans le vide avec un enregistrement qui réussissait.
     */
    public function testUnComplementSePoseSeLitEtSeRetire(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $parent = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);
        $complement = $this->idProduit(OffreFixtures::PRODUIT_CARTE);

        $client->request('POST', '/api/complementary_products', $entete + [
            'json' => [
                'product' => '/api/produits/' . $parent,
                'complement' => '/api/produits/' . $complement,
                'mode' => 'required',
                'defaultQuantity' => 2,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $lien = $client->getResponse()->toArray();
        self::assertSame('required', $lien['mode']);
        self::assertSame(2, $lien['defaultQuantity']);

        // Il se lit, filtré par le produit porteur.
        $liste = $client->request('GET', '/api/complementary_products', $entete + [
            'query' => ['product' => '/api/produits/' . $parent],
        ])->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        self::assertCount(1, $membres, 'témoin : sans lien lisible, la suite ne prouverait rien');

        // Et il se retire — ce que l'ancien mécanisme ne savait pas faire.
        $client->request('DELETE', '/api/complementary_products/' . $lien['id'], $entete);
        self::assertResponseStatusCodeSame(204);

        $apres = $client->request('GET', '/api/complementary_products', $entete + [
            'query' => ['product' => '/api/produits/' . $parent],
        ])->toArray();
        self::assertCount(0, $apres['member'] ?? $apres['hydra:member']);
    }

    /**
     * ⚠ LE FILTRE `product` DOIT EXISTER AU MAPPING, SINON LA COLLECTION SORT ENTIÈRE.
     *
     * API Platform ignore **en silence** un filtre déclaré sur une propriété inconnue : le paramètre
     * est accepté, la documentation l'annonce, et la liste sort complète. On vérifie donc qu'un
     * filtre sur un AUTRE produit ne rend pas le lien posé plus haut.
     */
    public function testLeFiltreProduitReduitVraiment(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $parent = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);
        $autre = $this->idProduit(OffreFixtures::PRODUIT_CARTE);

        $client->request('POST', '/api/complementary_products', $entete + [
            'json' => [
                'product' => '/api/produits/' . $parent,
                'complement' => '/api/produits/' . $autre,
                'mode' => 'suggested',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $chezLeParent = $client->request('GET', '/api/complementary_products', $entete + [
            'query' => ['product' => '/api/produits/' . $parent],
        ])->toArray();
        self::assertGreaterThan(
            0,
            \count($chezLeParent['member'] ?? $chezLeParent['hydra:member']),
            'témoin : le lien doit être lisible chez son porteur, sinon le filtre est vide pour une autre raison',
        );

        $chezLAutre = $client->request('GET', '/api/complementary_products', $entete + [
            'query' => ['product' => '/api/produits/' . $autre],
        ])->toArray();
        self::assertCount(
            0,
            $chezLAutre['member'] ?? $chezLAutre['hydra:member'],
            'Le filtre `product` ne réduit rien : il porte sur une propriété que le mapping ne connaît pas, et la collection sort entière.',
        );
    }
}
