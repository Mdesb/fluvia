<?php

declare(strict_types=1);

namespace App\Website\Controller;

use App\Website\Entity\BlogCategory;
use App\Website\Service\BlogReader;
use App\Website\Service\ContentBlocks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le site public de Fluvia, rendu par le serveur (ED-10).
 *
 * ⚠ **CES ROUTES NE SONT ATTEIGNABLES QUE PAR L'HÔTE DE LA VITRINE**, et c'est nginx qui le décide,
 * pas une contrainte de domaine écrite ici. Le vhost `smartaccess` ne proxifie vers PHP qu'une liste
 * de préfixes (`/api`, `/auth`, `/me`…) : `/blog` y tombe sur le frontal React et n'atteint jamais
 * ce contrôleur. Le vhost `vitrine`, lui, sert un fichier s'il existe et appelle l'application sinon.
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
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/', name: 'website_home', methods: ['GET'])]
    #[Cache(public: true, maxage: 300, mustRevalidate: true)]
    public function home(): Response
    {
        return $this->render('website/home.html.twig', [
            'blocs' => $this->blocs->valeurs(),
            // Trois articles au plus : l'accueil vend la plateforme, il ne remplace pas le blog.
            'derniers' => $this->blog->publies(new \DateTimeImmutable(), 3),
        ]);
    }

    #[Route('/blog', name: 'website_blog_index', methods: ['GET'])]
    #[Cache(public: true, maxage: 300, mustRevalidate: true)]
    public function blog(#[\Symfony\Component\HttpKernel\Attribute\MapQueryParameter] int $page = 1): Response
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
    public function rubrique(string $slug, #[\Symfony\Component\HttpKernel\Attribute\MapQueryParameter] int $page = 1): Response
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
        ]);
        $reponse->headers->set('Content-Type', 'application/xml; charset=UTF-8');

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
        ]);
    }
}
