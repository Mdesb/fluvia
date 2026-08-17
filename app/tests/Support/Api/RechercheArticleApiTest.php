<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Support\DataFixtures\SupportFixtures;
use App\Tests\Support\SupportApiTestCase;

/**
 * Recherche plein-texte publique (US-SUP-01/07, RG-SUP-06) : CA-1 (visibilité anonyme stricte),
 * CA-6 (aucun résultat + capacité d'ouvrir un ticket réservée aux exploitants authentifiés).
 */
final class RechercheArticleApiTest extends SupportApiTestCase
{
    public function testCa1RechercheAnonymeNeRetourneQueLesArticlesPubliesUsagerOuTousEtGlobal(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);

        $categorie = $client->request('POST', '/api/categorie_aides', $entete + ['json' => ['nom' => 'Recherche ' . uniqid()]])->toArray();

        // Article publié, ciblé "tous" : doit apparaître.
        $articlePublicTous = $client->request('POST', '/api/article_aides', $entete + [
            'json' => [
                'titre' => 'Encaisser une vente au comptoir',
                'categorie' => '/api/categorie_aides/' . $categorie['id'],
                'contenu' => 'Pour encaisser une vente, ouvrez la caisse puis scannez les articles vendus.',
                'portee' => 'global',
                'publicCible' => 'tous',
            ],
        ])->toArray();
        $client->request('POST', '/api/support/articles/' . $articlePublicTous['id'] . '/publier', $entete);

        // Article publié, ciblé "agent" : ne doit jamais apparaître pour un anonyme.
        $articleAgent = $client->request('POST', '/api/article_aides', $entete + [
            'json' => [
                'titre' => 'Encaisser une vente en mode dégradé (procédure agent)',
                'categorie' => '/api/categorie_aides/' . $categorie['id'],
                'contenu' => 'Procédure interne agent pour encaisser une vente en mode dégradé caisse.',
                'portee' => 'global',
                'publicCible' => 'agent',
            ],
        ])->toArray();
        $client->request('POST', '/api/support/articles/' . $articleAgent['id'] . '/publier', $entete);

        // Article non publié (brouillon) sur le même sujet : ne doit jamais apparaître.
        $client->request('POST', '/api/article_aides', $entete + [
            'json' => [
                'titre' => 'Brouillon encaisser une vente',
                'categorie' => '/api/categorie_aides/' . $categorie['id'],
                'contenu' => 'Brouillon non publié sur encaisser une vente.',
                'portee' => 'global',
                'publicCible' => 'tous',
            ],
        ]);

        $anonyme = static::createClient();
        $resultats = $anonyme->request('GET', '/api/support/articles/recherche?q=' . urlencode('encaisser vente'))->toArray();
        $membres = $this->extraireHydraMembers($resultats);

        self::assertNotEmpty($membres);
        $titres = array_column($membres, 'titre');
        self::assertContains('Encaisser une vente au comptoir', $titres);
        self::assertNotContains('Encaisser une vente en mode dégradé (procédure agent)', $titres);
        self::assertNotContains('Brouillon encaisser une vente', $titres);
    }

    public function testCa6RechercheSansResultatEtatVideEtOuvertureTicketReserveeAuxExploitantsAuthentifies(): void
    {
        $anonyme = static::createClient();
        $vide = $anonyme->request('GET', '/api/support/articles/recherche?q=' . urlencode('mot-cle-totalement-inexistant-xyz'))->toArray();
        self::assertSame([], $this->extraireHydraMembers($vide));

        // Anonyme : ne peut pas ouvrir de ticket (aucune suggestion possible, cas limite §8 spec).
        $anonyme->request('POST', '/api/support/tickets', [
            'json' => ['sujet' => 'Test', 'description' => 'Test', 'priorite' => 'basse'],
        ]);
        self::assertResponseStatusCodeSame(401);

        // Exploitant authentifié : peut ouvrir un ticket (suggestion possible côté UI).
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);
        $client->request('POST', '/api/support/tickets', $entete + [
            'json' => ['sujet' => 'Résultat de recherche vide', 'description' => 'Aucun article trouvé.', 'priorite' => 'basse'],
        ]);
        self::assertResponseIsSuccessful();
    }

    /** @return list<array<string, mixed>> */
    private function extraireHydraMembers(array $collection): array
    {
        return $collection['member'] ?? $collection['hydra:member'] ?? [];
    }
}
