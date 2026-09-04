<?php

declare(strict_types=1);

namespace App\Tests\Website;

use App\DataFixtures\SocleFixtures;
use App\Tests\SocleApiTestCase;
use App\Website\Entity\BlogPost;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-10 — l'administration du site de l'éditeur, vue par HTTP.
 *
 * Ce qui est vérifié ici tient en quatre refus et deux acceptations. Les refus comptent davantage :
 * un CMS qui accepte tout produit des pages cassées **après** l'enregistrement, c'est-à-dire loin de
 * celui qui a écrit, et sans que rien n'échoue.
 */
final class EditorWebsiteApiTest extends SocleApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    /** Le chemin nominal : un article naît en brouillon, avec une adresse dérivée de son titre. */
    public function testUnArticleNaitEnBrouillonAvecUneAdresseDeriveeDuTitre(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/posts');

        $client = static::createClient();
        $reponse = $client->request('POST', '/api/editor/website/posts', $this->commeEditeur($client) + [
            'json' => [
                'title' => 'Comment nous gérons les créneaux de piscine',
                'excerpt' => 'Un chapô.',
                'body' => '<p>Le corps.</p>',
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('comment-nous-gerons-les-creneaux-de-piscine', $reponse['slug']);
        self::assertSame('draft', $reponse['status'], 'un article neuf n’est jamais public sans qu’on l’ait décidé');
        self::assertFalse($reponse['visible']);
    }

    /**
     * ⚠ **LE CORPS EST ASSAINI À L'ENREGISTREMENT, ET ÇA SE VOIT DANS LA RÉPONSE.**
     *
     * Le témoin positif compte autant que le refus : si l'assainisseur retirait tout, le
     * `assertStringNotContainsString` passerait sur une chaîne vide et ne prouverait rien.
     */
    public function testLeCorpsEstAssainiAlecriture(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/posts');

        $client = static::createClient();
        $reponse = $client->request('POST', '/api/editor/website/posts', $this->commeEditeur($client) + [
            'json' => [
                'title' => 'Un article avec du code',
                'excerpt' => 'Un chapô.',
                'body' => '<p>Un <strong>texte</strong>.</p><script>alert(1)</script><p onclick="voler()">Suite.</p>',
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();

        // Témoin positif : ce qui doit rester est resté.
        self::assertStringContainsString('<strong>texte</strong>', $reponse['body']);
        self::assertStringContainsString('Suite.', $reponse['body']);

        // Et ce qui doit partir est parti — le script AVEC son contenu, pas seulement sa balise.
        self::assertStringNotContainsString('<script', $reponse['body']);
        self::assertStringNotContainsString('alert(1)', $reponse['body']);
        self::assertStringNotContainsString('onclick', $reponse['body']);
    }

    /**
     * ⚠ **UNE ADRESSE PUBLIÉE NE SE RENOMME PAS**, et le refus est un 409 que l'écran sait lire.
     *
     * Un lien publié est une promesse tenue par quelqu'un d'autre : un partage, un signet, un
     * résultat de recherche. Le renommer ne casse rien ici — ça casse chez eux, et personne de ce
     * côté ne le voit.
     */
    public function testUneAdressePublieeNeSeRenommePas(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/posts');

        $client = static::createClient();

        $cree = $client->request('POST', '/api/editor/website/posts', $this->commeEditeur($client) + [
            'json' => [
                'title' => 'Un article publié',
                'excerpt' => 'Un chapô.',
                'body' => '<p>Le corps.</p>',
                'status' => 'published',
            ],
        ])->toArray();

        self::assertSame('published', $cree['status']);
        self::assertNotNull($cree['publishedAt'], 'publier sans date laisserait l’article invisible pour toujours');
        self::assertTrue($cree['visible']);

        // ⚠ `+` SUR DEUX TABLEAUX GARDE LA CLEF DE GAUCHE. Fusionner ainsi `commeEditeur()` avec des
        // en-tetes propres les faisait disparaitre en silence, et API Platform repondait 415 sur un
        // PATCH sans `merge-patch+json`. On construit donc le tableau d'un seul tenant.
        $refus = $client->request('PATCH', '/api/editor/website/posts/'.$cree['id'], [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => [
                'Content-Type' => 'application/merge-patch+json',
                'X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
            ],
            'json' => ['slug' => 'une-autre-adresse'],
        ]);

        self::assertSame(409, $refus->getStatusCode(), (string) $refus->getContent(false));

        $this->em()->clear();
        $apres = $this->em()->getRepository(BlogPost::class)->find($cree['id']);
        self::assertInstanceOf(BlogPost::class, $apres);
        self::assertSame('un-article-publie', $apres->getSlug(), 'l’adresse n’a pas bougé');
    }

    /**
     * Un bloc que le gabarit ne connaît pas est refusé.
     *
     * L'accepter rangerait en base un contenu que rien ne rend : écrit, enregistré, invisible. Le
     * rédacteur croirait avoir publié.
     *
     * ⚠ **404 ET NON 422, ET C'EST LE FOURNISSEUR QUI TRANCHE AVANT LE PROCESSEUR.** API Platform lit
     * la ressource avant de l'écrire : une clé non déclarée n'existe pas, donc la requête s'arrête
     * là. Le refus du service (`UnknownBlockException`, 422) reste en place pour tout appelant qui
     * ne passe pas par HTTP — une commande d'import, une reprise — mais aucune requête ne l'atteint.
     */
    public function testUnBlocInconnuEstRefuse(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/blocks');

        $client = static::createClient();
        $refus = $client->request('PUT', '/api/editor/website/blocks/home.inconnu', $this->commeEditeur($client) + [
            'json' => ['id' => 'home.inconnu', 'value' => ['text' => 'Un texte perdu']],
        ]);

        self::assertSame(404, $refus->getStatusCode(), (string) $refus->getContent(false));
    }

    /**
     * ⚠ **LA LISTE DES BLOCS MONTRE CEUX QUI N'ONT JAMAIS ÉTÉ REMPLIS.**
     *
     * C'est toute la raison pour laquelle la liste vient du code et non de la table : lister la table
     * cacherait exactement les blocs sur lesquels il y a quelque chose à faire.
     */
    public function testLaListeDesBlocsMontreCeuxQuiNontJamaisEteRemplis(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/blocks');

        $client = static::createClient();
        $reponse = $client->request('GET', '/api/editor/website/blocks', $this->commeEditeur($client))->toArray();

        self::assertResponseIsSuccessful();

        $blocs = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertNotEmpty($blocs, 'sinon les vérifications qui suivent ne prouveraient rien');

        $parCle = array_column($blocs, null, 'id');
        self::assertArrayHasKey('home.hero.title', $parCle);
        self::assertNull($parCle['home.hero.title']['value'], 'jamais rempli en base : la valeur est nulle, et la page ne rend rien');
        self::assertSame('line', $parCle['home.hero.title']['type']);
    }

    /**
     * ⚠ **LE COMPTEUR D'ARTICLES D'UNE RUBRIQUE COMPTE VRAIMENT.**
     *
     * Il rendait ZÉRO pour toutes les rubriques, y compris pleines : Doctrine liait l'objet sans son
     * type `uuid`, la requête restait valide et ne comptait rien. Aucune exception, aucun
     * avertissement — et « 0 article » se lit comme un fait, pas comme une panne. C'est le garde-fou
     * des références libres qui l'a vu ; aucun test ne l'aurait montré, parce qu'aucun ne comptait.
     */
    public function testLeCompteurDarticlesDuneRubriqueCompteVraiment(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/categories');

        $client = static::createClient();

        $rubrique = $client->request('POST', '/api/editor/website/categories', $this->commeEditeur($client) + [
            'json' => ['name' => 'Exploitation'],
        ])->toArray();

        foreach (['Premier billet', 'Second billet'] as $titre) {
            $client->request('POST', '/api/editor/website/posts', $this->commeEditeur($client) + [
                'json' => ['title' => $titre, 'excerpt' => 'Chapô.', 'body' => '<p>Corps.</p>', 'categoryId' => $rubrique['id']],
            ]);
        }

        $liste = $client->request('GET', '/api/editor/website/categories', $this->commeEditeur($client))->toArray();
        $rubriques = $liste['member'] ?? $liste['hydra:member'] ?? [];

        self::assertNotEmpty($rubriques, 'sinon le compte ci-dessous ne prouverait rien');
        self::assertSame(2, $rubriques[0]['postCount']);
    }

    /** Un exploitant qui n'est pas l'éditeur ne voit pas que ces écrans existent. */
    public function testUnAutreEtablissementNeVoitPasLeSite(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/posts');

        $client = static::createClient();
        $refus = $client->request('GET', '/api/editor/website/posts', [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ]);

        self::assertSame(404, $refus->getStatusCode(), 'un 403 confirmerait à un curieux que l’écran existe');
    }

    // ---------------------------------------------------------------- montage

    /** @return array<string, mixed> */
    private function commeEditeur(object $client): array
    {
        /** @var \ApiPlatform\Symfony\Bundle\Test\Client $client */
        return [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];
    }

    private function sauterSiRouteAbsente(string $suffixe): void
    {
        static::createClient();

        /** @var \Symfony\Component\Routing\RouterInterface $routeur */
        $routeur = static::getContainer()->get('router');

        foreach ($routeur->getRouteCollection() as $route) {
            if (str_ends_with($route->getPath(), $suffixe)) {
                return;
            }
        }

        self::markTestSkipped('`src/Website/ApiResource` absent de `mapping.paths` (C9).');
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
