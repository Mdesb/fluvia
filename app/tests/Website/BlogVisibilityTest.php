<?php

declare(strict_types=1);

namespace App\Tests\Website;

use App\Website\Entity\BlogPost;
use App\Website\Enum\PublicationStatus;
use App\Website\Service\BlogReader;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ED-10 — « publié » veut dire la même chose des deux côtés.
 *
 * ⚠ **LA RÈGLE EST ÉCRITE DEUX FOIS, ET C'EST ASSUMÉ.** {@see BlogPost::isVisible()} répond sur un
 * objet qu'on tient déjà ; {@see BlogReader} filtre en SQL, parce qu'on ne charge pas mille articles
 * pour en garder trois. Deux expressions de la même règle finissent toujours par diverger — celle
 * qu'on modifie et celle qu'on oublie. Ce test les confronte sur les **quatre** cas, et c'est la
 * seule chose qui empêche la divergence de passer inaperçue.
 *
 * Le cas qui coûte cher est le troisième : un article **publié mais daté au futur**. Une règle qui
 * ne regarderait que le statut le servirait immédiatement — et un article programmé pour lundi
 * partirait le jeudi, sans que rien n'échoue.
 */
final class BlogVisibilityTest extends SocleApiTestCase
{
    private \DateTimeImmutable $maintenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maintenant = new \DateTimeImmutable('2026-09-04 12:00:00');
    }

    /**
     * @return iterable<string, array{PublicationStatus, string|null, bool, string}>
     */
    public static function cas(): iterable
    {
        yield 'brouillon daté au passé' => [
            PublicationStatus::Draft, '2026-09-01 09:00:00', false,
            'un brouillon reste un brouillon, même daté : la date seule publierait tout ce qui traîne',
        ];

        yield 'publié sans date' => [
            PublicationStatus::Published, null, false,
            'sans date, on ne sait pas à partir de quand : le servir serait décider à la place du rédacteur',
        ];

        yield 'publié daté au futur' => [
            PublicationStatus::Published, '2026-09-30 08:00:00', false,
            'programmé pour plus tard — le statut seul l’aurait publié aujourd’hui',
        ];

        yield 'publié daté au passé' => [
            PublicationStatus::Published, '2026-09-01 09:00:00', true,
            'le seul cas public',
        ];
    }

    // ⚠ ATTRIBUT, PAS ANNOTATION. PHPUnit 13 ne lit plus `@dataProvider` : il appelle la méthode
    // SANS argument, et l'échec parle d'un nombre d'arguments — jamais du fournisseur ignoré.
    #[DataProvider('cas')]
    public function testLesDeuxExpressionsDeLaRegleDisentLaMemeChose(
        PublicationStatus $statut,
        ?string $publieLe,
        bool $attendu,
        string $pourquoi,
    ): void {
        $article = $this->article('cas-'.md5($statut->value.($publieLe ?? 'null')), $statut, $publieLe);

        // 1. La règle en PHP, sur l'objet.
        self::assertSame($attendu, $article->isVisible($this->maintenant), 'isVisible() — '.$pourquoi);

        // 2. La même règle en SQL, par le lecteur public.
        $lecteur = new BlogReader($this->em());
        $trouve = null !== $lecteur->parSlug($article->getSlug(), $this->maintenant);

        self::assertSame($attendu, $trouve, 'BlogReader — '.$pourquoi);
    }

    /**
     * Le compte et la liste voient exactement les mêmes articles.
     *
     * Un compteur qui ne filtrerait pas comme la liste afficherait « 4 articles » au-dessus d'une
     * page qui en montre un — et la pagination proposerait des pages vides à un robot.
     */
    public function testLeCompteEtLaListeVoientLaMemeChose(): void
    {
        $this->article('publie-1', PublicationStatus::Published, '2026-09-01 09:00:00');
        $this->article('publie-2', PublicationStatus::Published, '2026-09-02 09:00:00');
        $this->article('brouillon', PublicationStatus::Draft, '2026-09-01 09:00:00');
        $this->article('programme', PublicationStatus::Published, '2026-12-01 09:00:00');

        $lecteur = new BlogReader($this->em());

        self::assertCount(2, $lecteur->publies($this->maintenant, 50));
        self::assertSame(2, $lecteur->compterPublies($this->maintenant));
    }

    /** Le plus récent d'abord : c'est ce qu'un lecteur attend d'un blog, et ce que la page promet. */
    public function testLesArticlesSortentDuPlusRecentAuPlusAncien(): void
    {
        $this->article('ancien', PublicationStatus::Published, '2026-08-01 09:00:00');
        $this->article('recent', PublicationStatus::Published, '2026-09-03 09:00:00');
        $this->article('moyen', PublicationStatus::Published, '2026-08-20 09:00:00');

        $slugs = array_map(
            static fn (BlogPost $a): string => $a->getSlug(),
            (new BlogReader($this->em()))->publies($this->maintenant, 50),
        );

        self::assertSame(['recent', 'moyen', 'ancien'], $slugs);
    }

    private function article(string $slug, PublicationStatus $statut, ?string $publieLe): BlogPost
    {
        $article = (new BlogPost())
            ->setSlug($slug)
            ->setTitle('Titre de '.$slug)
            ->setExcerpt('Chapô.')
            ->setBody('<p>Corps.</p>')
            ->setStatus($statut)
            ->setPublishedAt(null === $publieLe ? null : new \DateTimeImmutable($publieLe));

        $this->em()->persist($article);
        $this->em()->flush();

        return $article;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
