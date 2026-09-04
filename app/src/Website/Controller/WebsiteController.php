<?php

declare(strict_types=1);

namespace App\Website\Controller;

use App\Website\Entity\BlogCategory;
use App\Website\Service\BlogReader;
use App\Website\Service\ContentBlocks;
use App\Website\Service\MetierCatalog;
use App\Website\Service\ModuleCatalog;
use App\Website\Service\ModuleFamilies;
use App\Website\Service\SiteFaq;
use App\Website\Service\StructuredData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Le site public de Fluvia, rendu par le serveur (ED-10, ED-11).
 *
 * ⚠ **CES ROUTES NE SONT ATTEIGNABLES QUE PAR L'HÔTE DE LA VITRINE**, et c'est nginx qui le décide,
 * pas une contrainte de domaine écrite ici. Le vhost `smartaccess` ne proxifie vers PHP qu'une liste
 * de préfixes (`/api`, `/auth`, `/me`…) : `/blog` et `/modules` y tombent sur le frontal React et
 * n'atteignent jamais ce contrôleur. Le vhost `vitrine`, lui, sert un fichier s'il existe et appelle
 * l'application sinon.
 *
 * Écrire `host:` dans les attributs de route aurait figé le domaine dans le code — alors que ce site
 * doit changer d'adresse le jour où il quitte `vitrine.hector-conseil.com`.
 *
 * ⚠ **AUCUNE DE CES PAGES NE LIT UNE SESSION.** Ce sont des pages publiques, mises en cache par le
 * navigateur et par tout ce qui se trouve devant. Une page publique qui varierait selon l'utilisateur
 * est une fuite en attente : le cache servirait à l'un ce qui a été calculé pour l'autre.
 */
final class WebsiteController extends AbstractController
{
    /** Combien d'articles par page de liste. */
    private const PAR_PAGE = 9;

    public function __construct(
        private readonly BlogReader $blog,
        private readonly ContentBlocks $blocs,
        private readonly ModuleCatalog $modules,
        private readonly ModuleFamilies $familles,
        private readonly MetierCatalog $metiers,
        private readonly StructuredData $donnees,
        private readonly EntityManagerInterface $em,
        /**
         * L'unique interrupteur d'indexation.
         *
         * ⚠ Il commande la balise `robots` de chaque page ET le `robots.txt`. Avant le 04/09 la
         * consigne vivait dans le vhost nginx, en DEUX endroits — l'en-tête et le fichier — et son
         * propre commentaire prévenait que « retirer l'un sans l'autre ne suffit pas ». Deux
         * interrupteurs pour une décision, c'est un site à moitié indexé le jour où quelqu'un n'en
         * trouve qu'un.
         */
        #[Autowire(env: 'bool:WEBSITE_INDEXABLE')] private readonly bool $indexable = false,
        #[Autowire(env: 'VITRINE_BASE_URL')] private readonly string $baseUrl = '',
    ) {
    }

    #[Route('/', name: 'website_home', methods: ['GET'])]
    #[Cache(public: true, maxage: 300, mustRevalidate: true)]
    public function home(): Response
    {
        $questions = SiteFaq::generales();

        return $this->render('website/home.html.twig', [
            'blocs' => $this->blocs->valeurs(),
            'familles' => $this->familles->familles(),
            'modulesParFamille' => $this->modulesParFamille(),
            'metiers' => $this->metiers->tous(),
            // La table de rangement descend jusqu'au navigateur : voir {@see self::modulesParFamille}.
            'famillesPourLeNavigateur' => $this->familles->pourLeNavigateur(),
            // Trois articles au plus : l'accueil vend la plateforme, il ne remplace pas le blog.
            'derniers' => $this->blog->publies(new \DateTimeImmutable(), 3),
            'questions' => $questions,
            'jsonld' => [
                $this->donnees->organisation(),
                $this->donnees->application(array_column($this->modules->modules(), 'libelle')),
                $this->donnees->questions($questions),
            ],
        ]);
    }

    #[Route('/modules', name: 'website_modules', methods: ['GET'])]
    #[Cache(public: true, maxage: 900, mustRevalidate: true)]
    public function modules(): Response
    {
        $questions = SiteFaq::generales();

        return $this->render('website/modules_index.html.twig', [
            'rubriques' => $this->modules->parRubrique(),
            'questions' => $questions,
            'jsonld' => [
                $this->donnees->organisation(),
                $this->donnees->application(array_column($this->modules->modules(), 'libelle')),
                $this->donnees->questions($questions),
                $this->donnees->filDariane([
                    ['nom' => 'Accueil', 'url' => $this->absolue('website_home')],
                    ['nom' => 'Modules', 'url' => $this->absolue('website_modules')],
                ]),
            ],
        ]);
    }

    /**
     * La page d'un module.
     *
     * **Deux sources, et elles ne disent pas la même chose.** La description courte vient du
     * catalogue technique — donc elle est celle du produit, et elle ne peut pas en diverger. Le texte
     * long vient d'un bloc de contenu que l'éditeur remplit quand il veut : il ajoute de la
     * profondeur là où quelqu'un a écrit, et son absence ne laisse pas un trou — la page tient sans lui.
     */
    #[Route('/modules/{slug}', name: 'website_module', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    #[Cache(public: true, maxage: 900, mustRevalidate: true)]
    public function module(string $slug): Response
    {
        $module = $this->modules->parSlug($slug);

        if (null === $module) {
            throw $this->createNotFoundException('Ce module n’existe pas.');
        }

        $valeurs = $this->blocs->valeurs();
        $corps = $valeurs[ModuleCatalog::cleDeBloc($module['code'])]['html'] ?? '';

        // Les autres modules de la même rubrique : un maillage interne qui a un sens pour le lecteur
        // avant d'en avoir un pour un moteur. Une liste de « modules liés » choisie au hasard n'aide
        // ni l'un ni l'autre.
        $voisins = array_values(array_filter(
            $this->modules->modules(),
            static fn (array $autre): bool => $autre['categorie'] === $module['categorie'] && $autre['slug'] !== $module['slug'],
        ));

        return $this->render('website/module.html.twig', [
            'module' => $module,
            'corps' => $corps,
            'voisins' => \array_slice($voisins, 0, 4),
            'jsonld' => [
                $this->donnees->organisation(),
                $this->donnees->application([$module['libelle']], 'Fluvia — '.$module['libelle'], $module['description']),
                $this->donnees->filDariane([
                    ['nom' => 'Accueil', 'url' => $this->absolue('website_home')],
                    ['nom' => 'Modules', 'url' => $this->absolue('website_modules')],
                    ['nom' => $module['libelle'], 'url' => $this->urlModule($module['slug'])],
                ]),
            ],
        ]);
    }

    /**
     * Les métiers — le seul endroit du site qui parle la langue de l'acheteur (ED-12).
     *
     * Personne ne cherche « plateforme modulaire » : on cherche « logiciel gestion piscine ». Ces
     * cinq pages existent pour ça, et elles ne sont pas une reformulation des pages de modules : ce
     * qu'elles montrent, ce sont les objets que chaque verticale porte réellement — un POSS, un parc
     * de patins, un quota de salle.
     */
    #[Route('/metiers', name: 'website_metiers', methods: ['GET'])]
    #[Cache(public: true, maxage: 900, mustRevalidate: true)]
    public function metiers(): Response
    {
        return $this->render('website/metiers_index.html.twig', [
            'metiers' => $this->metiers->tous(),
            'jsonld' => [
                $this->donnees->organisation(),
                $this->donnees->filDariane([
                    ['nom' => 'Accueil', 'url' => $this->absolue('website_home')],
                    ['nom' => 'Métiers', 'url' => $this->absolue('website_metiers')],
                ]),
            ],
        ]);
    }

    #[Route('/metiers/{slug}', name: 'website_metier', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    #[Cache(public: true, maxage: 900, mustRevalidate: true)]
    public function metier(string $slug): Response
    {
        $metier = $this->metiers->parSlug($slug);

        if (null === $metier) {
            throw $this->createNotFoundException('Ce métier n’existe pas.');
        }

        $valeurs = $this->blocs->valeurs();

        return $this->render('website/metier.html.twig', [
            'metier' => $metier,
            'corps' => $valeurs[MetierCatalog::cleDeBloc($metier['code'])]['html'] ?? '',
            'jsonld' => [
                $this->donnees->organisation(),
                $this->donnees->application(
                    array_column($metier['modules'], 'libelle'),
                    'Fluvia — '.$metier['nom'],
                    $metier['chapo'],
                ),
                $this->donnees->filDariane([
                    ['nom' => 'Accueil', 'url' => $this->absolue('website_home')],
                    ['nom' => 'Métiers', 'url' => $this->absolue('website_metiers')],
                    ['nom' => $metier['nom'], 'url' => $this->absolue('website_metier', ['slug' => $metier['slug']])],
                ]),
            ],
        ]);
    }

    #[Route('/blog', name: 'website_blog_index', methods: ['GET'])]
    #[Cache(public: true, maxage: 300, mustRevalidate: true)]
    public function blog(#[MapQueryParameter] int $page = 1): Response
    {
        return $this->listerLesArticles(null, $page);
    }

    /**
     * ⚠ **DÉCLARÉE AVANT `/blog/{slug}`, ET AVEC UN PRÉFIXE QUI NE PEUT PAS ÊTRE UN SLUG.** Deux
     * routes qui se ressemblent, c'est la première déclarée qui gagne ; et si une rubrique
     * s'appelait « rubrique », son adresse serait ambiguë. Le préfixe `/blog/rubrique/` la place
     * dans un espace d'adresses distinct, ce qui rend l'ambiguïté impossible plutôt que rare.
     */
    #[Route('/blog/rubrique/{slug}', name: 'website_blog_category', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    #[Cache(public: true, maxage: 300, mustRevalidate: true)]
    public function rubrique(string $slug, #[MapQueryParameter] int $page = 1): Response
    {
        $rubrique = $this->em->getRepository(BlogCategory::class)->findOneBy(['slug' => $slug]);

        if (!$rubrique instanceof BlogCategory) {
            throw $this->createNotFoundException('Cette rubrique n’existe pas.');
        }

        return $this->listerLesArticles($rubrique, $page);
    }

    #[Route('/blog/rss.xml', name: 'website_blog_rss', methods: ['GET'])]
    #[Cache(public: true, maxage: 900, mustRevalidate: true)]
    public function rss(): Response
    {
        $reponse = $this->render('website/rss.xml.twig', [
            'articles' => $this->blog->publies(new \DateTimeImmutable(), 20),
        ]);
        $reponse->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');

        return $reponse;
    }

    #[Route('/sitemap.xml', name: 'website_sitemap', methods: ['GET'])]
    #[Cache(public: true, maxage: 3600, mustRevalidate: true)]
    public function sitemap(): Response
    {
        $reponse = $this->render('website/sitemap.xml.twig', [
            // 5 000 : la borne d'un plan de site est à 50 000 adresses, et on n'écrira pas dix mille
            // articles. La borne existe pour que la page ne devienne jamais une requête sans limite.
            'articles' => $this->blog->publies(new \DateTimeImmutable(), 5000),
            'rubriques' => $this->em->getRepository(BlogCategory::class)->findBy([], ['name' => 'ASC']),
            'modules' => $this->modules->modules(),
            'metiers' => $this->metiers->tous(),
        ]);
        $reponse->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $reponse;
    }

    /**
     * `robots.txt` — servi par l'application, et non par nginx.
     *
     * ⚠ **C'EST CE DÉPLACEMENT QUI REND L'OUVERTURE POSSIBLE EN UN GESTE.** Tant que la consigne
     * vivait dans le vhost, ouvrir le site demandait d'éditer nginx, de le recharger, ET de ne pas
     * oublier l'en-tête `X-Robots-Tag` posé douze lignes plus haut. Ici, une variable d'environnement
     * décide des deux, et la page le dit d'elle-même.
     */
    #[Route('/robots.txt', name: 'website_robots', methods: ['GET'])]
    #[Cache(public: true, maxage: 3600, mustRevalidate: true)]
    public function robots(): Response
    {
        $reponse = $this->render('website/robots.txt.twig', [
            'indexable' => $this->indexable,
            'sitemap' => $this->absolue('website_sitemap'),
        ]);
        $reponse->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        return $reponse;
    }

    /**
     * `llms.txt` — ce que le site dit de lui-même aux assistants génératifs.
     *
     * **Pourquoi ce fichier existe.** Un assistant qui répond « quel logiciel pour gérer une
     * piscine ? » ne parcourt pas un site : il lit ce qu'il trouve vite et le résume. Un fichier
     * texte qui énumère ce qu'est le produit, ce qu'il fait, et où lire le détail, lui donne de quoi
     * citer juste au lieu de paraphraser de travers.
     *
     * ⚠ **IL SUIT LE MÊME INTERRUPTEUR QUE `robots.txt`.** Un site fermé aux moteurs qui tendrait un
     * résumé aux assistants dirait deux choses opposées — et la seconde serait celle qui circule.
     */
    #[Route('/llms.txt', name: 'website_llms', methods: ['GET'])]
    #[Cache(public: true, maxage: 3600, mustRevalidate: true)]
    public function llms(): Response
    {
        $reponse = $this->render('website/llms.txt.twig', [
            'indexable' => $this->indexable,
            'rubriques' => $this->modules->parRubrique(),
            'metiers' => $this->metiers->tous(),
            'questions' => SiteFaq::generales(),
            'articles' => $this->blog->publies(new \DateTimeImmutable(), 20),
        ]);
        $reponse->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        return $reponse;
    }

    /**
     * ⚠ **DÉCLARÉE EN DERNIER**, et son `slug` n'accepte ni point ni barre oblique : sans cette
     * exigence, `/blog/rss.xml` serait avalé ici et rendrait « article introuvable » pour un flux qui
     * existe. Le symptôme apparaîtrait chez les agrégateurs, pas chez nous.
     */
    #[Route('/blog/{slug}', name: 'website_blog_post', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    #[Cache(public: true, maxage: 300, mustRevalidate: true)]
    public function article(string $slug): Response
    {
        $maintenant = new \DateTimeImmutable();
        $article = $this->blog->parSlug($slug, $maintenant);

        if (null === $article) {
            // 404 pour un brouillon comme pour une adresse inventée : distinguer les deux dirait à un
            // inconnu qu'un article existe et n'est pas encore publié.
            throw $this->createNotFoundException('Cet article n’existe pas.');
        }

        $suivants = array_values(array_filter(
            $this->blog->publies($maintenant, 4),
            static fn ($autre): bool => $autre->getSlug() !== $article->getSlug(),
        ));

        return $this->render('website/blog_post.html.twig', [
            'article' => $article,
            'suivants' => \array_slice($suivants, 0, 3),
            'jsonld' => [
                $this->donnees->organisation(),
                $this->donnees->article($article),
                $this->donnees->filDariane([
                    ['nom' => 'Accueil', 'url' => $this->absolue('website_home')],
                    ['nom' => 'Blog', 'url' => $this->absolue('website_blog_index')],
                    ['nom' => $article->getTitle(), 'url' => $this->urlArticle($article->getSlug())],
                ]),
            ],
        ]);
    }

    private function listerLesArticles(?BlogCategory $rubrique, int $page): Response
    {
        $maintenant = new \DateTimeImmutable();
        $page = max(1, $page);
        $slug = $rubrique?->getSlug();

        $total = $this->blog->compterPublies($maintenant, $slug);
        $pages = max(1, (int) ceil($total / self::PAR_PAGE));

        return $this->render('website/blog_index.html.twig', [
            'articles' => $this->blog->publies($maintenant, self::PAR_PAGE, ($page - 1) * self::PAR_PAGE, $slug),
            'rubrique' => $rubrique,
            'rubriques' => $this->em->getRepository(BlogCategory::class)->findBy([], ['name' => 'ASC']),
            'page' => $page,
            'pages' => $pages,
            'jsonld' => [
                $this->donnees->organisation(),
                $this->donnees->filDariane([
                    ['nom' => 'Accueil', 'url' => $this->absolue('website_home')],
                    ['nom' => 'Blog', 'url' => $this->absolue('website_blog_index')],
                ]),
            ],
        ]);
    }

    /**
     * Les modules vendables, groupés par famille éditoriale.
     *
     * ⚠ **AUCUN MODULE NE DOIT SE PERDRE ICI.** `ModuleFamilies::pour()` range tout code inconnu
     * dans une famille de refuge plutôt que de rendre `null` : un module vendable — donc facturé —
     * absent de la page qui liste ce qu'on vend serait invisible comme défaut, puisqu'il ne
     * manquerait nulle part. Le test `ModuleFamiliesTest` vérifie l'égalité des comptes.
     *
     * @return array<string, list<array{slug: string, code: string, libelle: string, description: string, categorie: string}>>
     */
    private function modulesParFamille(): array
    {
        $parFamille = [];

        foreach ($this->familles->familles() as $famille) {
            $parFamille[$famille['cle']] = [];
        }

        foreach ($this->modules->modules() as $module) {
            $parFamille[$this->familles->pour($module['code'])][] = $module;
        }

        return $parFamille;
    }

    /**
     * L'adresse publique d'une page — construite sur `VITRINE_BASE_URL`, jamais sur la requête.
     *
     * Voir {@see \App\Website\Service\StructuredData} pour le pourquoi : derrière le proxy,
     * l'absolu tiré de la requête rendait `http://` sur un site servi en HTTPS.
     *
     * @param array<string, mixed> $parametres
     */
    private function absolue(string $route, array $parametres = []): string
    {
        return rtrim($this->baseUrl, '/').$this->generateUrl($route, $parametres);
    }

    private function urlModule(string $slug): string
    {
        return $this->absolue('website_module', ['slug' => $slug]);
    }

    private function urlArticle(string $slug): string
    {
        return $this->absolue('website_blog_post', ['slug' => $slug]);
    }
}
