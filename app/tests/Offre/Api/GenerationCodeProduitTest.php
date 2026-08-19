<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;

/**
 * Génération automatique du code produit à la création : l'utilisateur ne saisit jamais de code
 * (invisible), le système en génère un unique. Un code fourni explicitement reste accepté.
 */
final class GenerationCodeProduitTest extends OffreApiTestCase
{
    /** Création sans code : réponse 201 avec un code non vide généré par le système. */
    public function testCreationSansCodeGenereUnCodeNonVide(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idTypeEntree = $this->idType(OffreFixtures::TYPE_ENTREE);

        $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Sans code explicite'],
                'type' => '/api/type_produits/' . $idTypeEntree,
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $cree = $client->getResponse()->toArray();
        self::assertArrayHasKey('code', $cree);
        self::assertNotSame('', $cree['code'], 'Un code doit être généré automatiquement.');
    }

    /** Deux créations sans code : deux codes générés distincts (pas de collision). */
    public function testDeuxCreationsSansCodeProduisentDesCodesDistincts(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idTypeEntree = $this->idType(OffreFixtures::TYPE_ENTREE);

        $premier = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Produit un'],
                'type' => '/api/type_produits/' . $idTypeEntree,
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();

        $second = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Produit deux'],
                'type' => '/api/type_produits/' . $idTypeEntree,
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();

        self::assertNotSame('', $premier['code']);
        self::assertNotSame('', $second['code']);
        self::assertNotSame($premier['code'], $second['code'], 'Les codes générés doivent être distincts.');
    }

    /** Un code fourni explicitement à la création reste respecté (rétrocompatibilité). */
    public function testCodeExplicitementFourniEstRespecte(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];
        $idTypeEntree = $this->idType(OffreFixtures::TYPE_ENTREE);

        $cree = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Produit avec code manuel'],
                'code' => 'MANUEL-42',
                'type' => '/api/type_produits/' . $idTypeEntree,
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('MANUEL-42', $cree['code']);
    }
}
