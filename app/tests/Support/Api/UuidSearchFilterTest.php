<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Support\DataFixtures\SupportFixtures;
use App\Tests\Support\SupportApiTestCase;

/**
 * FILTRER UNE COLLECTION PAR UNE RÉFÉRENCE `Uuid`.
 *
 * ── CE QUE CE TEST TIENT ────────────────────────────────────────────────────────────────────────
 *
 * Le `SearchFilter` d'API Platform, posé sur une propriété dont l'identifiant est un `Uuid`, rendait
 * **toujours une liste vide** : l'identifiant est stocké en `BINARY(16)`, le comparer à une chaîne de
 * 36 caractères ne trouve rien — et ne lève rien. L'audit `bin/audit-uuid.php`, qui interroge le
 * mapping Doctrine et non les noms, en a compté 145 dans 28 modules.
 *
 * `UuidAwareSearchFilterPass` remplace les 112 services de filtre par
 * {@see \App\Platform\Filter\UuidAwareSearchFilter}. Ce test échoue si la substitution n'a plus lieu.
 *
 * ⚠ POURQUOI CE TEST DE PLATEFORME VIT DANS `Support`. Le harnais ne charge que les fixtures du
 * module du cas de test. Un test posé sous `tests/Platform` n'aurait aucune donnée à filtrer, et
 * rendrait une liste vide — c'est-à-dire exactement le symptôme qu'il doit détecter, mais pour une
 * autre raison. On teste là où il y a de quoi compter.
 *
 * ⚠ LE TÉMOIN AVANT L'ASSERTION. La collection non filtrée est comptée d'abord : sans elle, « le
 * filtre rend une ligne » ne se distingue pas de « il n'y en avait qu'une ». Un test dont l'échec et
 * la réussite se ressemblent ne prouve rien.
 */
final class UuidSearchFilterTest extends SupportApiTestCase
{
    public function testFiltrerParUneReferenceUuidRendLesLignesDeCetteReference(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);

        $categorieVente = $this->creerCategorie($client, $entete, 'Vente');
        $categorieAcces = $this->creerCategorie($client, $entete, 'Acces');

        $articleVente = $this->creerArticle($client, $entete, $categorieVente['id'], 'Encaisser une vente');
        $this->creerArticle($client, $entete, $categorieAcces['id'], 'Ouvrir un tourniquet');

        // ── LE TÉMOIN ───────────────────────────────────────────────────────────────────────────
        // Deux articles au moins existent et sont visibles sans filtre. Si cette assertion tombe,
        // toutes celles qui suivent sont muettes : « zéro » ne voudrait plus rien dire.
        $tous = $this->extraireMembres($client->request('GET', '/api/article_aides', $entete)->toArray());
        self::assertGreaterThanOrEqual(2, \count($tous), 'Témoin absent : sans articles, ce test ne prouve rien.');

        // ── LA MESURE ───────────────────────────────────────────────────────────────────────────
        $filtres = $this->extraireMembres($client->request(
            'GET',
            '/api/article_aides?categorie=/api/categorie_aides/'.$categorieVente['id'],
            $entete,
        )->toArray());

        self::assertCount(1, $filtres, 'Le filtre sur une référence Uuid doit rendre la ligne de cette référence.');
        self::assertSame($articleVente['id'], $filtres[0]['id']);
    }

    public function testUnIdentifiantNu_SansIri_EstAccepteAussi(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);

        $categorie = $this->creerCategorie($client, $entete, 'Billetterie');
        $article = $this->creerArticle($client, $entete, $categorie['id'], 'Imprimer un billet');

        // L'écran envoie une IRI, un script envoie souvent l'identifiant nu. Les deux sont acceptés :
        // imposer une forme reviendrait à casser l'appelant qui n'en connaît qu'une.
        $filtres = $this->extraireMembres($client->request(
            'GET',
            '/api/article_aides?categorie='.$categorie['id'],
            $entete,
        )->toArray());

        self::assertCount(1, $filtres);
        self::assertSame($article['id'], $filtres[0]['id']);
    }

    public function testUneValeurIllisibleFermeLaCollectionAuLieuDeLOuvrir(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);

        $categorie = $this->creerCategorie($client, $entete, 'Divers');
        $this->creerArticle($client, $entete, $categorie['id'], 'Article temoin');

        $tous = $this->extraireMembres($client->request('GET', '/api/article_aides', $entete)->toArray());
        self::assertNotEmpty($tous, 'Témoin absent : sans article, « collection fermée » ne se distingue pas de « rien à montrer ».');

        // ⚠ LE CONTRÔLE QUI REND CE TEST DISCRIMINANT. Sans lui, ce test restait VERT même en
        // retirant la passe de substitution : le filtre cassé rend zéro, la fermeture volontaire
        // rend zéro, et l'assertion ne sait pas les distinguer. Vérifié en cassant pour de bon.
        // Une valeur LISIBLE doit d'abord rendre sa ligne ; alors seulement « zéro » veut dire
        // quelque chose.
        $lisible = $this->extraireMembres($client->request(
            'GET',
            '/api/article_aides?categorie='.$categorie['id'],
            $entete,
        )->toArray());
        self::assertCount(1, $lisible, 'Contrôle absent : si le filtre ne rend rien de valide, sa fermeture ne prouve rien.');

        // ⚠ LE SENS SÛR DE L'ERREUR EST CELUI QUI RESTREINT. Rendre la collection entière à qui se
        // trompe de paramètre montrerait des lignes que personne n'a demandées.
        $filtres = $this->extraireMembres($client->request(
            'GET',
            '/api/article_aides?categorie=ceci-nest-pas-un-uuid',
            $entete,
        )->toArray());

        self::assertSame([], $filtres);
    }

    /** @return array<string, mixed> */
    private function creerCategorie(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $nom): array
    {
        $reponse = $client->request('POST', '/api/categorie_aides', $entete + [
            'json' => ['nom' => $nom.' '.uniqid()],
        ])->toArray();
        self::assertResponseIsSuccessful();

        return $reponse;
    }

    /** @return array<string, mixed> */
    private function creerArticle(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        array $entete,
        string $categorieId,
        string $titre,
    ): array {
        $reponse = $client->request('POST', '/api/article_aides', $entete + [
            'json' => [
                'titre' => $titre.' '.uniqid(),
                'categorie' => '/api/categorie_aides/'.$categorieId,
                'resume' => 'Résumé de test.',
                'contenu' => 'Contenu de test, suffisamment long pour passer les contraintes.',
                'motsCles' => ['test'],
                'portee' => 'global',
                'publicCible' => 'tous',
                'moduleLie' => 'vente',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        return $reponse;
    }

    /** @return list<array<string, mixed>> */
    private function extraireMembres(array $collection): array
    {
        return array_values($collection['member'] ?? $collection['hydra:member'] ?? []);
    }
}
