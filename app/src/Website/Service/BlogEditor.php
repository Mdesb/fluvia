<?php

declare(strict_types=1);

namespace App\Website\Service;

use App\Website\Entity\BlogCategory;
use App\Website\Entity\BlogPost;
use App\Website\Enum\PublicationStatus;
use App\Website\Exception\PublishedSlugIsFrozenException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Écrire un article : les règles qui ne doivent pas vivre dans un écran (ED-10).
 *
 * **Pourquoi un service et pas un processeur.** Trois chemins écrivent un article — la création, la
 * modification, la publication — et ils partagent l'assainissement, le slug et l'horodatage. Laisser
 * ces règles dans le processeur HTTP les rendrait invisibles à tout ce qui n'est pas HTTP : une
 * commande d'import, une reprise, un futur écran. C'est le défaut relevé sur la souscription au
 * guichet, où les règles vivaient dans le handler en ligne et nulle part ailleurs.
 */
final readonly class BlogEditor
{
    public function __construct(
        private EntityManagerInterface $em,
        private SlugGenerator $slugs,
        private BodySanitizer $sanitizer,
    ) {
    }

    /**
     * @param array<string, mixed> $champs
     */
    public function creer(array $champs, \DateTimeImmutable $instant): BlogPost
    {
        $article = new BlogPost();
        $this->appliquer($article, $champs, $instant);

        $this->em->persist($article);
        $this->em->flush();

        return $article;
    }

    /**
     * @param array<string, mixed> $champs
     *
     * @throws PublishedSlugIsFrozenException si l'on tente de renommer l'adresse d'un article publié
     */
    public function modifier(BlogPost $article, array $champs, \DateTimeImmutable $instant): BlogPost
    {
        $this->appliquer($article, $champs, $instant);
        $this->em->flush();

        return $article;
    }

    /**
     * @param array<string, mixed> $champs
     */
    private function appliquer(BlogPost $article, array $champs, \DateTimeImmutable $instant): void
    {
        // ⚠ RELEVÉ AVANT TOUTE MODIFICATION, ET C'EST UNE CORRECTION.
        //
        // Le gel de l'adresse porte sur un article **déjà publié avant cette requête**, pas sur celui
        // qu'on est en train de publier. Lu après coup — c'est-à-dire après que le statut a été
        // appliqué quelques lignes plus bas — il aurait refusé de nommer un article au moment même de
        // sa première publication, qui est le seul moment où l'on choisit vraiment son adresse. Le
        // refus serait tombé sur le geste le plus normal du parcours.
        $etaitPublie = PublicationStatus::Published === $article->getStatus() && '' !== $article->getSlug();

        if (\array_key_exists('title', $champs)) {
            $article->setTitle(trim((string) $champs['title']));
        }

        if (\array_key_exists('excerpt', $champs)) {
            $article->setExcerpt(trim((string) $champs['excerpt']));
        }

        if (\array_key_exists('body', $champs)) {
            // Assaini ICI, à l'écriture. Voir le commentaire de classe de `BodySanitizer` : le rendu
            // fait confiance à la colonne, et il a raison de le faire parce que rien d'autre n'y entre.
            $article->setBody($this->sanitizer->sanitize((string) $champs['body']));
        }

        if (\array_key_exists('coverUrl', $champs)) {
            $article->setCoverUrl($this->texteOuNull($champs['coverUrl']));
        }

        if (\array_key_exists('coverAlt', $champs)) {
            $article->setCoverAlt($this->texteOuNull($champs['coverAlt']));
        }

        if (\array_key_exists('authorName', $champs)) {
            $article->setAuthorName($this->texteOuNull($champs['authorName']));
        }

        if (\array_key_exists('metaDescription', $champs)) {
            $article->setMetaDescription($this->texteOuNull($champs['metaDescription']));
        }

        if (\array_key_exists('categoryId', $champs)) {
            $article->setCategory($this->rubrique($champs['categoryId']));
        }

        $this->appliquerLaPublication($article, $champs, $instant);
        $this->appliquerLeSlug($article, $champs, $etaitPublie);

        $article->touch($instant);
    }

    /**
     * @param array<string, mixed> $champs
     */
    private function appliquerLaPublication(BlogPost $article, array $champs, \DateTimeImmutable $instant): void
    {
        if (!\array_key_exists('status', $champs)) {
            return;
        }

        $statut = PublicationStatus::tryFrom((string) $champs['status']) ?? $article->getStatus();
        $article->setStatus($statut);

        if (PublicationStatus::Published !== $statut) {
            return;
        }

        // Publier sans date, c'est publier maintenant. Sans ce défaut, un article passé en
        // « publié » resterait invisible pour toujours — la lecture publique exige une date, et
        // l'écran n'aurait affiché aucune erreur : il l'aurait montré publié.
        $demandee = $champs['publishedAt'] ?? null;

        if (\is_string($demandee) && '' !== $demandee) {
            $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $demandee)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $demandee)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d', $demandee);

            if (false !== $date) {
                $article->setPublishedAt($date);

                return;
            }
        }

        if (null === $article->getPublishedAt()) {
            $article->setPublishedAt($instant);
        }
    }

    /**
     * @param array<string, mixed> $champs
     * @param bool                 $dejaPublie l'article était-il publié AVANT cette requête ?
     *
     * @throws PublishedSlugIsFrozenException
     */
    private function appliquerLeSlug(BlogPost $article, array $champs, bool $dejaPublie): void
    {
        $demande = \is_string($champs['slug'] ?? null) ? trim($champs['slug']) : '';

        if ('' === $article->getSlug()) {
            $article->setSlug($this->slugs->unique('' !== $demande ? $demande : $article->getTitle(), $article->getId()->toRfc4122()));

            return;
        }

        if ('' === $demande) {
            return;
        }

        $souhaite = $this->slugs->unique($demande, $article->getId()->toRfc4122());

        if ($souhaite === $article->getSlug()) {
            return;
        }

        // ⚠ UN LIEN PUBLIÉ EST UNE PROMESSE TENUE PAR QUELQU'UN D'AUTRE. Un partage, un signet, un
        // résultat de recherche, un lien entrant : renommer l'adresse ne casse rien ici, ça casse
        // chez eux, et personne de ce côté ne le voit. Tant qu'aucune redirection permanente n'existe,
        // on refuse plutôt que de laisser faire — le refus se lit, la page disparue ne se lit pas.
        if ($dejaPublie) {
            throw new PublishedSlugIsFrozenException(sprintf(
                'L\'adresse « %s » est publiée : elle ne peut plus changer. Les liens déjà partagés '
                .'pointeraient dans le vide, et rien ici ne le signalerait.',
                $article->getSlug(),
            ));
        }

        $article->setSlug($souhaite);
    }

    private function rubrique(mixed $id): ?BlogCategory
    {
        if (!\is_string($id) || '' === $id) {
            return null;
        }

        $rubrique = $this->em->getRepository(BlogCategory::class)->find($id);

        return $rubrique instanceof BlogCategory ? $rubrique : null;
    }

    private function texteOuNull(mixed $valeur): ?string
    {
        if (!\is_string($valeur)) {
            return null;
        }

        $texte = trim($valeur);

        return '' === $texte ? null : $texte;
    }
}
