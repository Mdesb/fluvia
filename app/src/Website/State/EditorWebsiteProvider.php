<?php

declare(strict_types=1);

namespace App\Website\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Subscription\Security\EditorOnly;
use App\Website\ApiResource\EditorBlogCategory;
use App\Website\ApiResource\EditorBlogPost;
use App\Website\ApiResource\EditorContentBlock;
use App\Website\Entity\BlogCategory;
use App\Website\Entity\BlogPost;
use App\Website\Service\ContentBlocks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture du site de l'éditeur depuis son administration (ED-10).
 *
 * **Un fournisseur pour les trois ressources**, comme `EditorCatalogProvider` en sert deux : la règle
 * d'accès est la même, et trois classes jumelles divergeraient au premier correctif appliqué à une
 * seule.
 *
 * ⚠ **CE FOURNISSEUR REND LES BROUILLONS, ET C'EST TOUTE LA DIFFÉRENCE AVEC LE PUBLIC.**
 * {@see \App\Website\Service\BlogReader} ne rend jamais qu'un article publié et daté au passé ; ici
 * on rend tout, parce que c'est le seul endroit où un brouillon peut être repris. Les deux lectures
 * doivent donc rester séparées : une seule fonction paramétrée par un booléen finirait, un jour, par
 * recevoir le mauvais booléen depuis le contrôleur public.
 *
 * ---
 *
 * **@cloisonnement-verifie : le périmètre est le tenant éditeur, et il est vérifié en tête.**
 *
 * `assertEditor('editor.manage_website')` est la première ligne de `provide()` : une session qui
 * n'est pas celle de l'éditeur reçoit 404 avant qu'aucun identifiant ne soit lu. Le contrôle
 * canonique — recalculer l'autorité contre l'établissement de l'entité résolue — n'a rien à quoi
 * s'appliquer : `BlogPost`, `BlogCategory` et `ContentBlock` **n'ont pas d'établissement**, parce
 * qu'ils décrivent le site de l'éditeur et non les données d'un client. Il n'existe donc pas deux
 * périmètres entre lesquels un identifiant pourrait faire passer quelqu'un.
 *
 * @implements ProviderInterface<EditorBlogPost|EditorBlogCategory|EditorContentBlock>
 */
final class EditorWebsiteProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly ContentBlocks $blocs,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $this->editorOnly->assertEditor('editor.manage_website');

        $classe = $operation->getClass();
        $collection = $operation instanceof CollectionOperationInterface;

        if (EditorContentBlock::class === $classe) {
            return $collection ? $this->blocsDeclares() : $this->bloc((string) ($uriVariables['id'] ?? ''));
        }

        if (EditorBlogCategory::class === $classe) {
            if ($collection) {
                return $this->rubriques();
            }

            return isset($uriVariables['id'])
                ? $this->rubrique($this->uuid($uriVariables['id']))
                : new EditorBlogCategory();
        }

        if ($collection) {
            return $this->articles();
        }

        // Création : API Platform réclame un objet à hydrater, sans identifiant.
        return isset($uriVariables['id'])
            ? $this->article($this->uuid($uriVariables['id']))
            : new EditorBlogPost();
    }

    /** @return list<EditorBlogPost> */
    private function articles(): array
    {
        /** @var list<BlogPost> $articles */
        $articles = $this->em->getRepository(BlogPost::class)->findBy([], ['updatedAt' => 'DESC']);

        return array_map([$this, 'versArticle'], $articles);
    }

    private function article(Uuid $id): EditorBlogPost
    {
        $article = $this->em->getRepository(BlogPost::class)->find($id);

        if (!$article instanceof BlogPost) {
            throw new NotFoundHttpException('Cet article n’existe pas.');
        }

        return $this->versArticle($article);
    }

    /** @return list<EditorBlogCategory> */
    private function rubriques(): array
    {
        /** @var list<BlogCategory> $rubriques */
        $rubriques = $this->em->getRepository(BlogCategory::class)->findBy([], ['name' => 'ASC']);

        return array_map([$this, 'versRubrique'], $rubriques);
    }

    private function rubrique(Uuid $id): EditorBlogCategory
    {
        $rubrique = $this->em->getRepository(BlogCategory::class)->find($id);

        if (!$rubrique instanceof BlogCategory) {
            throw new NotFoundHttpException('Cette rubrique n’existe pas.');
        }

        return $this->versRubrique($rubrique);
    }

    /** @return list<EditorContentBlock> */
    private function blocsDeclares(): array
    {
        $lignes = [];

        foreach ($this->blocs->pourLAdministration() as $ligne) {
            $bloc = new EditorContentBlock();
            $bloc->id = $ligne['key'];
            $bloc->type = $ligne['type'];
            $bloc->label = $ligne['label'];
            $bloc->help = $ligne['help'];
            $bloc->value = $ligne['value'];

            $lignes[] = $bloc;
        }

        return $lignes;
    }

    private function bloc(string $cle): EditorContentBlock
    {
        foreach ($this->blocsDeclares() as $bloc) {
            if ($bloc->id === $cle) {
                return $bloc;
            }
        }

        throw new NotFoundHttpException('Ce bloc n’est déclaré par aucun gabarit.');
    }

    private function versArticle(BlogPost $article): EditorBlogPost
    {
        $vue = new EditorBlogPost();
        $vue->id = $article->getId()->toRfc4122();
        $vue->slug = $article->getSlug();
        $vue->title = $article->getTitle();
        $vue->excerpt = $article->getExcerpt();
        $vue->body = $article->getBody();
        $vue->coverUrl = $article->getCoverUrl();
        $vue->coverAlt = $article->getCoverAlt();
        $vue->status = $article->getStatus()->value;
        $vue->publishedAt = $article->getPublishedAt()?->format(\DateTimeInterface::ATOM);
        $vue->categoryId = $article->getCategory()?->getId()->toRfc4122();
        $vue->categoryName = $article->getCategory()?->getName();
        $vue->authorName = $article->getAuthorName();
        $vue->metaDescription = $article->getMetaDescription();
        $vue->visible = $article->isVisible(new \DateTimeImmutable());
        $vue->updatedAt = $article->getUpdatedAt()->format(\DateTimeInterface::ATOM);

        return $vue;
    }

    private function versRubrique(BlogCategory $rubrique): EditorBlogCategory
    {
        $vue = new EditorBlogCategory();
        $vue->id = $rubrique->getId()->toRfc4122();
        $vue->slug = $rubrique->getSlug();
        $vue->name = $rubrique->getName();
        $vue->description = $rubrique->getDescription();
        // ⚠ L'IDENTIFIANT ET SON TYPE, JAMAIS L'OBJET NU. Doctrine lie un objet par son identifiant
        // SANS le type `uuid` : la requête reste valide, ne lève rien, et compte ZÉRO. Le compteur
        // aurait affiché « 0 article » sur chaque rubrique, y compris celles qui en portent douze —
        // et zéro se lit comme un résultat, pas comme une panne. Deux modules en sont morts en
        // silence le 28/08 (solde de fidélité, file d'attente), pour exactement cette forme.
        $vue->postCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(BlogPost::class, 'a')
            ->where('a.category = :rubrique')
            ->setParameter('rubrique', $rubrique->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return $vue;
    }

    private function uuid(mixed $brut): Uuid
    {
        if (!\is_string($brut) || !Uuid::isValid($brut)) {
            throw new NotFoundHttpException();
        }

        return Uuid::fromString($brut);
    }
}
