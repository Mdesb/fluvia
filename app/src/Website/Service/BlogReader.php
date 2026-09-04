<?php

declare(strict_types=1);

namespace App\Website\Service;

use App\Website\Entity\BlogPost;
use App\Website\Enum\PublicationStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce que le public a le droit de lire (ED-10).
 *
 * ⚠ **UN SEUL ENDROIT DIT « PUBLIÉ », ET TOUT LE PUBLIC PASSE PAR LUI.** La page de liste, la page
 * d'article, le plan du site et le flux RSS ont chacun besoin de la même règle — `status = published`
 * ET `publishedAt <= maintenant`. Quatre copies de cette condition, c'est la garantie qu'un jour
 * l'une d'elles oublie la date : le plan du site déclarerait à Google des adresses de brouillons, et
 * Google les demanderait.
 *
 * ⚠ **ET LA MÊME RÈGLE EXISTE EN PHP** — {@see BlogPost::isVisible()}. Ce n'est pas une redondance
 * gratuite : celle-ci filtre en SQL parce qu'on ne charge pas mille articles pour en garder trois ;
 * celle-là répond sur un objet qu'on tient déjà. Un test les confronte sur les quatre cas, justement
 * parce que deux expressions de la même règle finissent par diverger.
 *
 * **Aucune méthode de cette classe ne rend un brouillon**, quel que soit son argument. C'est ce qui
 * permet au contrôleur public de n'avoir aucune condition à écrire — et donc aucune à oublier.
 */
final readonly class BlogReader
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Les articles publics, du plus récent au plus ancien.
     *
     * @return list<BlogPost>
     */
    public function publies(\DateTimeImmutable $instant, int $limite = 20, int $decalage = 0, ?string $rubriqueSlug = null): array
    {
        $qb = $this->requeteVisible($instant)
            ->orderBy('a.publishedAt', 'DESC')
            ->setMaxResults($limite)
            ->setFirstResult($decalage);

        if (null !== $rubriqueSlug) {
            $qb->join('a.category', 'r')->andWhere('r.slug = :rubrique')->setParameter('rubrique', $rubriqueSlug);
        }

        /** @var list<BlogPost> $articles */
        $articles = $qb->getQuery()->getResult();

        return $articles;
    }

    public function compterPublies(\DateTimeImmutable $instant, ?string $rubriqueSlug = null): int
    {
        $qb = $this->requeteVisible($instant)->select('COUNT(a.id)');

        if (null !== $rubriqueSlug) {
            $qb->join('a.category', 'r')->andWhere('r.slug = :rubrique')->setParameter('rubrique', $rubriqueSlug);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** L'article public portant cette adresse, ou `null` — y compris quand il existe mais n'est pas public. */
    public function parSlug(string $slug, \DateTimeImmutable $instant): ?BlogPost
    {
        $article = $this->requeteVisible($instant)
            ->andWhere('a.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $article instanceof BlogPost ? $article : null;
    }

    private function requeteVisible(\DateTimeImmutable $instant): \Doctrine\ORM\QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('a')
            ->from(BlogPost::class, 'a')
            ->where('a.status = :publie')
            ->andWhere('a.publishedAt IS NOT NULL')
            ->andWhere('a.publishedAt <= :instant')
            ->setParameter('publie', PublicationStatus::Published)
            ->setParameter('instant', $instant);
    }
}
