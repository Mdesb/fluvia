<?php

declare(strict_types=1);

namespace App\Tests\Website;

use App\Tests\SocleApiTestCase;
use App\Website\Entity\ContentBlock;
use App\Website\Service\ModuleCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-11 — les pages de modules, et ce qu'un moteur ou un assistant lit du site.
 *
 * ⚠ **CE QUI EST VÉRIFIÉ ICI N'EST PAS « la page répond ».** Une page de vente qui répond 200 sans
 * titre unique, sans description, sans données structurées et sans plan du site est invisible : elle
 * existe pour un visiteur qui a déjà le lien, et pour personne d'autre. Chaque affirmation faite au
 * référencement se vérifie donc en lisant le HTML servi.
 */
final class WebsiteSeoTest extends SocleApiTestCase
{
    /** La liste des modules vient du catalogue, et elle ne montre aucune verticale. */
    public function testLaListeDesModulesVientDuCatalogueEtExclutLesVerticales(): void
    {
        $this->sauterSiRouteAbsente('website_modules');

        $client = static::createClient();
        $reponse = $client->request('GET', '/modules');

        self::assertResponseIsSuccessful();
        $html = (string) $reponse->getContent();

        // Témoin positif : des modules réels du catalogue technique.
        //
        // ⚠ L'APOSTROPHE EST ÉCHAPPÉE PAR TWIG. Le libellé porte une apostrophe droite ; le HTML
        // servi contient `&#039;`. Chercher « d'accès » tel qu'écrit dans le catalogue échoue, et
        // l'échec accuse le catalogue au lieu de l'échappement — d'où la forme exacte ici.
        self::assertStringContainsString('Contrôle d&#039;accès', $html);
        self::assertStringContainsString('Prélèvement SEPA', $html);
        self::assertStringContainsString('/modules/controle-acces', $html);

        // ⚠ « Padel, ce n'est pas un module » — Maxime, 01/09. Les cinq verticales sont ce qu'un
        // établissement EST, pas ce qu'il ajoute à la carte : leur donner une page « module »
        // présenterait comme achetable ce que le tunnel ne sait pas vendre.
        self::assertStringNotContainsString('/modules/padel', $html);
        self::assertStringNotContainsString('/modules/patinoire', $html);
    }

    /** Chaque module a sa page, avec son titre propre et ses données structurées. */
    public function testLaPageDunModulePorteSonTitreEtSonBalisage(): void
    {
        $this->sauterSiRouteAbsente('website_module');

        $client = static::createClient();
        $reponse = $client->request('GET', '/modules/controle-acces');

        self::assertResponseIsSuccessful();
        $html = (string) $reponse->getContent();

        self::assertStringContainsString('<title>Contrôle d&#039;accès — Fluvia</title>', $html);
        self::assertStringContainsString('rel="canonical"', $html);
        self::assertStringContainsString('"@type":"SoftwareApplication"', $html);
        self::assertStringContainsString('"@type":"BreadcrumbList"', $html);
    }

    /**
     * Le texte long d'un module s'affiche quand il est écrit, et son absence ne casse rien.
     *
     * C'est ce qui permet aux vingt pages d'exister avant qu'une seule ligne soit rédigée.
     */
    public function testLeTexteLongDunModuleSaffiche(): void
    {
        $this->sauterSiRouteAbsente('website_module');

        $client = static::createClient();

        // Sans bloc : la page tient debout.
        $sans = (string) $client->request('GET', '/modules/casiers')->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Casiers', $sans);

        $this->bloc(ModuleCatalog::cleDeBloc('casiers'), ['html' => '<h2>Comment ça marche</h2><p>Un texte rédigé.</p>']);

        $avec = (string) $client->request('GET', '/modules/casiers')->getContent();
        self::assertStringContainsString('<h2>Comment ça marche</h2>', $avec);
        self::assertStringContainsString('Un texte rédigé.', $avec);
    }

    /** Un module qui n'existe pas rend 404, et non une page vide. */
    public function testUnModuleInconnuRend404(): void
    {
        $this->sauterSiRouteAbsente('website_module');

        $client = static::createClient();
        $reponse = $client->request('GET', '/modules/module-invente');

        self::assertSame(404, $reponse->getStatusCode());
    }

    /** Le plan du site déclare les pages de modules. */
    public function testLePlanDuSiteDeclareLesModules(): void
    {
        $this->sauterSiRouteAbsente('website_sitemap');

        $client = static::createClient();
        $xml = (string) $client->request('GET', '/sitemap.xml')->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/modules</loc>', $xml);
        self::assertStringContainsString('/modules/controle-acces</loc>', $xml);
    }

    /**
     * ⚠ **UN SEUL INTERRUPTEUR, ET LES TROIS SURFACES LE SUIVENT.**
     *
     * La balise `robots`, le fichier `robots.txt` et le résumé `llms.txt` disaient chacun la leur
     * avant ED-11 — deux vivaient dans nginx, le troisième n'existait pas. Un site à moitié ouvert
     * est le pire des deux états : il s'indexe sans qu'on l'ait décidé, et se referme mal.
     *
     * Ce test lit les trois dans le même état, puis dans l'autre. Vérifier un seul ne prouverait
     * rien : c'est leur ACCORD qui est la propriété.
     */
    public function testLesTroisSurfacesSuiventLeMemeInterrupteur(): void
    {
        $this->sauterSiRouteAbsente('website_robots');

        // ── Fermé ──
        $this->indexable(false);
        $client = static::createClient();

        self::assertStringContainsString('name="robots" content="noindex', (string) $client->request('GET', '/')->getContent());
        self::assertStringContainsString('Disallow: /', (string) $client->request('GET', '/robots.txt')->getContent());
        self::assertStringContainsString('site en préparation', (string) $client->request('GET', '/llms.txt')->getContent());

        // ── Ouvert ──
        self::ensureKernelShutdown();
        $this->indexable(true);
        $client = static::createClient();

        $accueil = (string) $client->request('GET', '/')->getContent();
        $robots = (string) $client->request('GET', '/robots.txt')->getContent();
        $llms = (string) $client->request('GET', '/llms.txt')->getContent();

        self::assertStringNotContainsString('name="robots"', $accueil, 'plus de noindex sur la page');
        self::assertStringContainsString('Allow: /', $robots);
        self::assertStringContainsString('Sitemap:', $robots, 'un robots.txt ouvert doit dire où est le plan du site');
        self::assertStringContainsString('# Fluvia', $llms);
        self::assertStringContainsString('/modules/controle-acces', $llms, 'le résumé doit lister les modules réels');
    }

    /**
     * Les questions déclarées aux moteurs sont VISIBLES sur la page.
     *
     * Un `FAQPage` dont les réponses ne s'affichent pas est du contenu caché — la faute la plus
     * courante de ce balisage, et elle est sanctionnée. Le témoin est donc double : la question dans
     * le JSON **et** la même question dans le HTML rendu.
     */
    public function testLesQuestionsBaliseesSontAussiAffichees(): void
    {
        $this->sauterSiRouteAbsente('website_home');

        $client = static::createClient();
        $html = (string) $client->request('GET', '/')->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"@type":"FAQPage"', $html);

        // ⚠ ET LA MÊME QUESTION DANS LA LISTE QUE LE VISITEUR LIT — c'est tout l'objet du test.
        // Chercher « après le dernier </script> » ne marchait pas : la page en porte d'autres, tout
        // en bas, pour les tarifs et le tunnel. On cherche donc la balise de définition elle-même,
        // qui n'existe que dans le HTML rendu et jamais dans le JSON.
        self::assertStringContainsString('<dt>Qu&#039;est-ce que Fluvia ?</dt>', $html);
    }

    /**
     * ⚠ **UN TITRE D'ARTICLE NE PEUT PAS FERMER LE BLOC DE DONNÉES STRUCTURÉES.**
     *
     * Sans `JSON_HEX_TAG`, un titre contenant `</script>` clôt le `<script type="application/ld+json">`
     * et le reste part en HTML — sur une page publique, avec un contenu que quelqu'un saisit dans un
     * écran. Le test écrit exactement ce titre.
     */
    public function testUnTitreQuiFermeUneBaliseNeCassePasLaPage(): void
    {
        $this->sauterSiRouteAbsente('website_blog_post');

        $article = (new \App\Website\Entity\BlogPost())
            ->setSlug('titre-piege')
            ->setTitle('Un titre</script><b>injecté</b>')
            ->setExcerpt('Chapô.')
            ->setBody('<p>Corps.</p>')
            ->setStatus(\App\Website\Enum\PublicationStatus::Published)
            ->setPublishedAt(new \DateTimeImmutable('-1 day'));
        $this->em()->persist($article);
        $this->em()->flush();

        $client = static::createClient();
        $html = (string) $client->request('GET', '/blog/titre-piege')->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"@type":"BlogPosting"', $html, 'témoin positif : le balisage est bien là');
        // La balise fermante n'apparaît nulle part telle quelle : ni dans le JSON, ni dans le HTML.
        self::assertStringNotContainsString('<\/script><b>', $html);
        self::assertStringNotContainsString('</script><b>injecté</b>', $html);
    }

    // ---------------------------------------------------------------- montage

    private function indexable(bool $ouvert): void
    {
        $_ENV['WEBSITE_INDEXABLE'] = $ouvert ? '1' : '0';
        $_SERVER['WEBSITE_INDEXABLE'] = $ouvert ? '1' : '0';
    }

    private function sauterSiRouteAbsente(string $nom): void
    {
        static::createClient();

        /** @var \Symfony\Component\Routing\RouterInterface $routeur */
        $routeur = static::getContainer()->get('router');

        if (null === $routeur->getRouteCollection()->get($nom)) {
            self::markTestSkipped(sprintf('Route « %s » absente.', $nom));
        }
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
