<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Support\DataFixtures\SupportFixtures;
use App\Tests\Support\SupportApiTestCase;

/** Catégories (US-SUP-03, RG-SUP-01) : suppression bloquée si non vide (cas limite §8 spec). */
final class CategorieAideApiTest extends SupportApiTestCase
{
    public function testSuppressionCategorieVideAutorisee(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);

        $categorie = $client->request('POST', '/api/categorie_aides', $entete + [
            'json' => ['nom' => 'Catégorie vide'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/support/categories/' . $categorie['id'] . '/supprimer', $entete);
        self::assertResponseIsSuccessful();
    }

    public function testSuppressionCategorieContenantUnArticleRefusee409(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);

        $categorie = $client->request('POST', '/api/categorie_aides', $entete + [
            'json' => ['nom' => 'Catégorie non vide'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/article_aides', $entete + [
            'json' => [
                'titre' => 'Article de test',
                'categorie' => '/api/categorie_aides/' . $categorie['id'],
                'contenu' => 'Contenu de test.',
                'portee' => 'global',
                'publicCible' => 'agent',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/support/categories/' . $categorie['id'] . '/supprimer', $entete);
        self::assertResponseStatusCodeSame(409);
    }

    public function testSuppressionCategorieContenantUneSousCategorieRefusee409(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);

        $parent = $client->request('POST', '/api/categorie_aides', $entete + [
            'json' => ['nom' => 'Parent'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/categorie_aides', $entete + [
            'json' => ['nom' => 'Enfant', 'parent' => '/api/categorie_aides/' . $parent['id']],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/support/categories/' . $parent['id'] . '/supprimer', $entete);
        self::assertResponseStatusCodeSame(409);
    }

    public function testFilArianeExposeParApi(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);

        $parent = $client->request('POST', '/api/categorie_aides', $entete + [
            'json' => ['nom' => 'Vente & Caisse'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $enfant = $client->request('POST', '/api/categorie_aides', $entete + [
            'json' => ['nom' => 'Encaissement', 'parent' => '/api/categorie_aides/' . $parent['id']],
        ])->toArray();
        self::assertResponseIsSuccessful();

        self::assertCount(2, $enfant['filAriane']);
        self::assertSame('Vente & Caisse', $enfant['filAriane'][0]['nom']);
        self::assertSame('Encaissement', $enfant['filAriane'][1]['nom']);
    }
}
