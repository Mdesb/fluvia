<?php

declare(strict_types=1);

namespace App\Tests\Website;

use App\Tests\SocleApiTestCase;
use App\Website\Entity\BlogPost;
use App\Website\Entity\ContentBlock;
use App\Website\Enum\PublicationStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-10 — les pages publiques, vues comme un visiteur et comme un robot.
 *
 * ⚠ **CES TESTS PASSENT PAR HTTP, ET C'EST LE SEUL MOYEN DE PROUVER CE QU'ILS PROUVENT.**
 * `BlogVisibilityTest` interroge les services : il resterait vert si le contrôleur n'existait pas,
 * si une route en avalait une autre, ou si un gabarit était introuvable. Ici on demande des adresses,
 * comme le fait Google.
 */
final class WebsitePagesTest extends SocleApiTestCase
{
    /** La page d'accueil rend le contenu rangé en base, et rien d'autre. */
    public function testLaccueilRendLesBlocsDeLaBase(): void
    {
        $this->sauterSiRouteAbsente('website_home');

        $this->bloc('home.hero.title', ['text' => 'Un titre écrit depuis l’administration']);
        $this->bloc('home.hero.lead', ['text' => "Premier paragraphe.\n\nSecond paragraphe."]);

        $client = static::createClient();
        $reponse = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $reponse->getContent();

        self::assertStringContainsString('Un titre écrit depuis l’administration', $html);
        self::assertStringContainsString('Premier paragraphe.', $html);
        self::assertStringContainsString('Second paragraphe.', $html, 'les paragraphes se séparent sur une ligne vide — voir le `split` du gabarit');
    }

    /**
     * ⚠ **UN BLOC JAMAIS REMPLI NE REND RIEN, ET SURTOUT PAS SA VALEUR D'ORIGINE.**
     *
     * C'est la contrepartie de la règle : l'écran d'administration montre le bloc vide, la page
     * montre la même chose. Si le gabarit se repliait sur `HomeBlocks::initialValue`, la page
     * afficherait un texte que l'écran déclare absent — et personne ne saurait lequel des deux ment.
     */
    public function testUnBlocJamaisRempliNeRendRien(): void
    {
        $this->sauterSiRouteAbsente('website_home');

        $client = static::createClient();
        $reponse = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $reponse->getContent();

        // Témoin positif : la page a bien été rendue. Sans lui, l'absence ci-dessous passerait sur
        // une réponse vide — et ce test dirait « aucun repli » sans avoir regardé une page.
        self::assertStringContainsString('Essayer gratuitement 14 jours', $html);

        // Le texte d'origine de `home.modules.title`, déclaré dans le code et jamais écrit en base.
        self::assertStringNotContainsString('Un socle commun, des modules à la carte', $html);
    }

    /** La liste ne montre que ce qui est public. */
    public function testLaListeNeMontreQueLesArticlesPublies(): void
    {
        $this->sauterSiRouteAbsente('website_blog_index');

        $this->article('article-public', 'Un article public', PublicationStatus::Published, '-2 days');
        $this->article('article-brouillon', 'Un brouillon', PublicationStatus::Draft, '-2 days');
        $this->article('article-programme', 'Un article programmé', PublicationStatus::Published, '+10 days');

        $client = static::createClient();
        $reponse = $client->request('GET', '/blog');

        self::assertResponseIsSuccessful();
        $html = (string) $reponse->getContent();

        self::assertStringContainsString('Un article public', $html);
        self::assertStringNotContainsString('Un brouillon', $html);
        self::assertStringNotContainsString('Un article programmé', $html, 'programmé pour dans dix jours : le publier aujourd’hui trahit le rédacteur');
    }

    /** Un brouillon n'a pas d'adresse publique, et son 404 ne dit pas qu'il existe. */
    public function testUnBrouillonRend404(): void
    {
        $this->sauterSiRouteAbsente('website_blog_post');

        $this->article('mon-brouillon', 'Mon brouillon', PublicationStatus::Draft, '-1 day');

        $client = static::createClient();
        // ⚠ `false` : sans lui, le client d'API Platform LEVE sur un 4xx, et le test échoue en
        // disant « une erreur est survenue » au lieu de vérifier le 404 qu'il attend.
        $reponse = $client->request('GET', '/blog/mon-brouillon');

        self::assertSame(404, $reponse->getStatusCode());

        $corps = (string) $reponse->getContent(false);
        // Témoin positif : on a bien lu une réponse. Une chaîne vide contiendrait « ni le titre du
        // brouillon, ni rien » et le test passerait sans avoir mesuré quoi que ce soit.
        // `assertNotEmpty` et non `assertNotSame('', …)` : c'est la forme que le garde-fou de
        // vacuité reconnaît, et une forme qu'il ne reconnaît pas est une garde qui ne protège personne.
        self::assertNotEmpty($corps, 'sinon l’absence ci-dessous ne prouverait rien');
        self::assertStringNotContainsString('Mon brouillon', $corps);
    }

    /**
     * ⚠ **LE FLUX RSS N'EST PAS AVALÉ PAR LA ROUTE DES ARTICLES.**
     *
     * `/blog/{slug}` et `/blog/rss.xml` se ressemblent. Sans l'exigence `[a-z0-9-]+` sur le slug, la
     * seconde tomberait dans la première et rendrait « article introuvable » — un 404 que personne
     * ne verrait, parce qu'un flux RSS est lu par des agrégateurs, pas par nous.
     */
    public function testLeFluxRssRepondEtNestPasPrisPourUnArticle(): void
    {
        $this->sauterSiRouteAbsente('website_blog_rss');

        $this->article('un-article', 'Un article public', PublicationStatus::Published, '-1 day');

        $client = static::createClient();
        $reponse = $client->request('GET', '/blog/rss.xml');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/rss+xml', implode(' ', $reponse->getHeaders()['content-type'] ?? []));
        self::assertStringContainsString('<title>Un article public</title>', (string) $reponse->getContent());
    }

    /**
     * Le plan du site ne déclare aucune adresse de brouillon.
     *
     * C'est le pire endroit où se tromper : Google demande ce qu'on lui déclare, et une page de
     * brouillon indexée ne se retire pas d'un clic.
     */
    public function testLePlanDuSiteNeDeclareAucunBrouillon(): void
    {
        $this->sauterSiRouteAbsente('website_sitemap');

        $this->article('visible-au-plan', 'Visible', PublicationStatus::Published, '-1 day');
        $this->article('cache-au-plan', 'Caché', PublicationStatus::Draft, '-1 day');

        $client = static::createClient();
        $reponse = $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        $xml = (string) $reponse->getContent();

        // Témoin positif d'abord : sans lui, les deux absences ci-dessous passeraient sur une page vide.
        self::assertStringContainsString('/blog/visible-au-plan', $xml);
        self::assertStringNotContainsString('cache-au-plan', $xml);
    }

    // ---------------------------------------------------------------- montage

    private function sauterSiRouteAbsente(string $nom): void
    {
        static::createClient();

        /** @var \Symfony\Component\Routing\RouterInterface $routeur */
        $routeur = static::getContainer()->get('router');

        if (null === $routeur->getRouteCollection()->get($nom)) {
            self::markTestSkipped(sprintf('Route « %s » absente.', $nom));
        }
    }

    private function article(string $slug, string $titre, PublicationStatus $statut, string $decalage): BlogPost
    {
        $article = (new BlogPost())
            ->setSlug($slug)
            ->setTitle($titre)
            ->setExcerpt('Le chapô de '.$titre.'.')
            ->setBody('<p>Le corps.</p>')
            ->setStatus($statut)
            ->setPublishedAt(new \DateTimeImmutable($decalage));

        $this->em()->persist($article);
        $this->em()->flush();

        return $article;
    }

    /** @param array<int|string, mixed> $valeur */
    private function bloc(string $cle, array $valeur): void
    {
        $bloc = (new ContentBlock($cle))->setValue($valeur, new \DateTimeImmutable());
        $this->em()->persist($bloc);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
