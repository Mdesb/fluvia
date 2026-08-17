<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Support\DataFixtures\SupportFixtures;
use App\Tests\Support\SupportApiTestCase;

/**
 * Article d'aide (US-SUP-02/04/05/06, RG-SUP-02/03/04) : publication (CA-2), cloisonnement local
 * (CA-3), filtrage par module lié (CA-4), versionnage (CA-5).
 */
final class ArticleAideApiTest extends SupportApiTestCase
{
    public function testCa2ArticleNonPublieInvisibleEnKbPubliqueEtVisibleApresPublication(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);

        $categorie = $this->creerCategorie($client, $entete, 'Vente');
        $article = $client->request('POST', '/api/article_aides', $entete + [
            'json' => [
                'titre' => 'Encaisser une vente au guichet',
                'categorie' => '/api/categorie_aides/' . $categorie['id'],
                'resume' => 'Étapes pour encaisser une vente.',
                'contenu' => 'Ouvrir la caisse, scanner les articles, encaisser le paiement.',
                'motsCles' => ['caisse', 'encaissement'],
                'portee' => 'global',
                'publicCible' => 'tous',
                'moduleLie' => 'vente',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('brouillon', $article['statut']);

        // Invisible en KB publique (anonyme) avant publication (CA-2).
        $anonyme = static::createClient();
        $publics = $anonyme->request('GET', '/api/support/articles/publics')->toArray();
        self::assertSame([], $this->extraireHydraMembers($publics));

        // Publication explicite.
        $client->request('POST', '/api/support/articles/' . $article['id'] . '/publier', $entete);
        self::assertResponseIsSuccessful();
        $publie = $client->request('GET', '/api/article_aides/' . $article['id'], $entete)->toArray();
        self::assertSame('publie', $publie['statut']);

        // Visible en KB publique après publication.
        $publicsApres = $anonyme->request('GET', '/api/support/articles/publics')->toArray();
        self::assertNotEmpty($this->extraireHydraMembers($publicsApres));
    }

    public function testCa3ArticleLocalEtablissementAInvisiblePourEtablissementB(): void
    {
        // Les catégories sont globales (§1.1 plan) : créées par un rédacteur global (gerer_categorie).
        [$clientGlobal, $enteteGlobal] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);
        $categorie = $this->creerCategorie($clientGlobal, $enteteGlobal, 'Local A');

        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_LOCAL_A, SupportFixtures::ETAB_A_NOM);
        $idEtabA = $this->idEtablissement(SupportFixtures::ETAB_A_NOM);

        $article = $client->request('POST', '/api/article_aides', $entete + [
            'json' => [
                'titre' => 'Consigne locale établissement A',
                'categorie' => '/api/categorie_aides/' . $categorie['id'],
                'contenu' => 'Contenu réservé au site A.',
                'portee' => 'local',
                'etablissement' => '/api/etablissements/' . $idEtabA,
                'publicCible' => 'usager',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/support/articles/' . $article['id'] . '/publier', $entete);
        self::assertResponseIsSuccessful();

        // Visible en KB publique dans le contexte de l'établissement A.
        $anonyme = static::createClient();
        $publicsA = $anonyme->request('GET', '/api/support/articles/publics?etablissement=' . $idEtabA)->toArray();
        self::assertNotEmpty($this->extraireHydraMembers($publicsA));

        // Invisible dans le contexte de l'établissement B.
        $idEtabB = $this->idEtablissement(SupportFixtures::ETAB_B_NOM);
        $publicsB = $anonyme->request('GET', '/api/support/articles/publics?etablissement=' . $idEtabB)->toArray();
        self::assertSame([], $this->extraireHydraMembers($publicsB));

        // Un rédacteur local de l'établissement B ne peut pas consulter l'article : filtré hors
        // périmètre par `PerimetreSupportExtension` (404), même convention que
        // `App\Tests\Sport\Api\CloisonnementTest` (RG-SOCLE-05).
        [$clientB, $enteteB] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_LOCAL_B, SupportFixtures::ETAB_B_NOM);
        $clientB->request('GET', '/api/article_aides/' . $article['id'], $enteteB);
        self::assertResponseStatusCodeSame(404, "Un rédacteur local B ne doit pas voir un article local de l'établissement A.");
    }

    public function testCa4FiltrageParModuleLie(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);

        $categorie = $this->creerCategorie($client, $entete, 'Filtrage module');

        $client->request('POST', '/api/article_aides', $entete + [
            'json' => [
                'titre' => 'Article vente',
                'categorie' => '/api/categorie_aides/' . $categorie['id'],
                'contenu' => 'Contenu vente.',
                'portee' => 'global',
                'publicCible' => 'agent',
                'moduleLie' => 'vente',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/article_aides', $entete + [
            'json' => [
                'titre' => 'Article stock',
                'categorie' => '/api/categorie_aides/' . $categorie['id'],
                'contenu' => 'Contenu stock.',
                'portee' => 'global',
                'publicCible' => 'agent',
                'moduleLie' => 'stock',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $resultats = $client->request('GET', '/api/article_aides?moduleLie=vente', $entete)->toArray();
        $membres = $this->extraireHydraMembers($resultats);
        self::assertNotEmpty($membres);
        foreach ($membres as $membre) {
            self::assertSame('vente', $membre['moduleLie']);
        }
    }

    public function testCa5ModificationArticlePublieCreeNouvelleVersionHistoriqueComplet(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);

        $categorie = $this->creerCategorie($client, $entete, 'Versionnage');
        $article = $client->request('POST', '/api/article_aides', $entete + [
            'json' => [
                'titre' => 'Article versionné',
                'categorie' => '/api/categorie_aides/' . $categorie['id'],
                'contenu' => 'Version 1.',
                'portee' => 'global',
                'publicCible' => 'agent',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/support/articles/' . $article['id'] . '/publier', $entete);
        self::assertResponseIsSuccessful();

        $client->request('PATCH', '/api/article_aides/' . $article['id'], [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['contenu' => 'Version 2, corrigée.'],
        ]);
        self::assertResponseIsSuccessful();

        $historique = $client->request('GET', '/api/support/articles/' . $article['id'] . '/historique', $entete)->toArray();
        $versions = $this->extraireHydraMembers($historique);
        self::assertCount(2, $versions);
    }

    /** @return array{id: string} */
    private function creerCategorie(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $nom): array
    {
        $reponse = $client->request('POST', '/api/categorie_aides', $entete + ['json' => ['nom' => $nom . ' ' . uniqid()]])->toArray();
        self::assertResponseIsSuccessful();

        return $reponse;
    }

    /** @return list<array<string, mixed>> */
    private function extraireHydraMembers(array $collection): array
    {
        return $collection['member'] ?? $collection['hydra:member'] ?? [];
    }
}
