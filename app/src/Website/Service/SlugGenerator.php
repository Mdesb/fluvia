<?php

declare(strict_types=1);

namespace App\Website\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Fabrique une adresse d'article lisible et unique (ED-10).
 *
 * **L'unicité se règle ici, pas par une erreur de base.** La colonne porte bien une contrainte
 * unique — c'est elle qui fait foi — mais un rédacteur qui publie « Nos tarifs » deux ans de suite
 * ne doit pas recevoir une erreur SQL : il reçoit `nos-tarifs-2`. La contrainte reste comme filet,
 * pour le cas où deux écritures simultanées passeraient ce contrôle.
 *
 * ⚠ **`bloc` EXCLUT L'ARTICLE QU'ON EST EN TRAIN DE MODIFIER.** Sans cette exclusion, renommer un
 * article sans changer son titre lui trouverait « son propre slug déjà pris » et le renommerait en
 * `-2` à chaque enregistrement. Le symptôme est comique et l'adresse change à chaque sauvegarde.
 */
final readonly class SlugGenerator
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param string      $souhaite  le texte à transformer — un titre, ou un slug proposé à la main
     * @param string|null $exclureId l'identifiant de l'article en cours de modification, s'il existe
     */
    public function unique(string $souhaite, ?string $exclureId = null): string
    {
        $base = $this->normaliser($souhaite);

        if ('' === $base) {
            // Un titre entièrement composé de ponctuation ou d'émoji donne un slug vide, et une
            // adresse vide sert la page de liste. On ne devine pas : on nomme.
            $base = 'article';
        }

        $candidat = $base;
        $suffixe = 1;

        while ($this->pris($candidat, $exclureId)) {
            ++$suffixe;
            $candidat = $base.'-'.$suffixe;
        }

        return $candidat;
    }

    private function normaliser(string $texte): string
    {
        $slug = (new AsciiSlugger('fr'))->slug($texte)->lower()->toString();

        // 160 caractères : la taille de la colonne. Tronquer ici plutôt que laisser la base le faire
        // en silence — MariaDB en mode non strict couperait sans rien dire, et l'adresse rendue par
        // l'écran ne serait pas celle enregistrée.
        return substr($slug, 0, 160);
    }

    private function pris(string $slug, ?string $exclureId): bool
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(\App\Website\Entity\BlogPost::class, 'a')
            ->where('a.slug = :slug')
            ->setParameter('slug', $slug);

        if (null !== $exclureId) {
            $qb->andWhere('a.id != :id')->setParameter('id', $exclureId);
        }

        return 0 < (int) $qb->getQuery()->getSingleScalarResult();
    }
}
