<?php

declare(strict_types=1);

namespace App\Website\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Subscription\Security\EditorOnly;
use App\Website\ApiResource\EditorBlogCategory;
use App\Website\ApiResource\EditorBlogPost;
use App\Website\ApiResource\EditorContentBlock;
use App\Website\ApiResource\EditorTrade;
use App\Website\Entity\BlogCategory;
use App\Website\Entity\BlogPost;
use App\Website\Entity\Trade;
use App\Website\Enum\PublicationStatus;
use App\Website\Exception\BuiltInTradeIsProtectedException;
use App\Website\Exception\PublishedSlugIsFrozenException;
use App\Website\Exception\TradeAlreadyExistsException;
use App\Website\Exception\UnknownActivityException;
use App\Website\Exception\UnknownBlockException;
use App\Website\Service\BlogEditor;
use App\Website\Service\ContentBlocks;
use App\Website\Service\SlugGenerator;
use App\Website\Service\TradeEditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Écriture du site de l'éditeur depuis son administration (ED-10).
 *
 * ⚠ **AUCUNE RÈGLE MÉTIER N'EST ÉCRITE ICI.** Le slug, l'assainissement du corps, la date de
 * publication et le gel d'une adresse publiée vivent dans {@see BlogEditor} ; la forme des blocs
 * dans {@see ContentBlocks}. Ce processeur traduit du HTTP en appels de service, et rien d'autre —
 * c'est ce qui permet à une commande d'import ou à une reprise d'obtenir exactement les mêmes règles
 * sans passer par une requête.
 *
 * ---
 *
 * **@cloisonnement-verifie : le périmètre est le tenant éditeur, et il est vérifié en tête.**
 *
 * `assertEditor('editor.manage_website')` est la première ligne de `process()`. Les entités écrites
 * ici n'ont pas d'établissement — elles décrivent le site de l'éditeur, pas les données d'un client
 * — donc aucun identifiant reçu ne peut faire franchir une frontière qui n'existe pas. La rubrique
 * résolue depuis le corps de la requête appartient au même site que celui qui l'écrit, par
 * construction : il n'y en a qu'un.
 *
 * **Les refus deviennent des codes que l'écran sait lire** : 409 pour une adresse gelée — le
 * rédacteur peut corriger et renvoyer —, 422 pour un bloc inconnu. Laisser remonter l'exception
 * donnerait un 500 sur un geste parfaitement ordinaire.
 */
final class EditorWebsiteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly BlogEditor $redaction,
        private readonly ContentBlocks $blocs,
        private readonly SlugGenerator $slugs,
        private readonly TradeEditor $redactionMetier,
        private readonly EditorTradeView $vueMetier,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->editorOnly->assertEditor('editor.manage_website');

        $maintenant = new \DateTimeImmutable();

        if ($data instanceof EditorContentBlock) {
            return $this->ecrireLeBloc($data, $maintenant);
        }

        if ($data instanceof EditorTrade) {
            return $this->ecrireLeMetier($data, $operation, $uriVariables);
        }

        if ($data instanceof EditorBlogCategory) {
            return $this->ecrireLaRubrique($data, $operation, $uriVariables);
        }

        if ($data instanceof EditorBlogPost) {
            return $this->ecrireLArticle($data, $operation, $uriVariables, $maintenant);
        }

        return $data;
    }

    /**
     * ⚠ **LES REFUS DEVIENNENT DES CODES QUE L'ÉCRAN SAIT LIRE**, comme pour les articles : 409 pour
     * une adresse gelée ou un code déjà pris — on corrige et on renvoie —, 422 pour une activité ou
     * un statut qui n'existent pas, 409 pour un métier que le produit porte lui-même. Laisser
     * remonter l'exception donnerait un 500 sur des gestes parfaitement ordinaires.
     *
     * ⚠ **AUCUNE RÈGLE N'EST ÉCRITE ICI**, conformément à l'en-tête de cette classe : elles vivent
     * toutes dans {@see TradeEditor}, pour qu'un import ou une reprise obtiennent exactement les
     * mêmes sans passer par une requête.
     *
     * @param array<string, mixed> $uriVariables
     */
    private function ecrireLeMetier(EditorTrade $vue, Operation $operation, array $uriVariables): ?EditorTrade
    {
        $ligne = isset($uriVariables['id'])
            ? $this->em->getRepository(Trade::class)->find($this->uuid($uriVariables['id']))
            : null;

        if (isset($uriVariables['id']) && !$ligne instanceof Trade) {
            throw new NotFoundHttpException('Ce métier n’existe pas.');
        }

        if ($operation instanceof Delete) {
            \assert($ligne instanceof Trade);

            try {
                $this->redactionMetier->supprimer($ligne);
            } catch (BuiltInTradeIsProtectedException $refus) {
                throw new ConflictHttpException($refus->getMessage(), $refus);
            }

            return null;
        }

        if ('' === trim($vue->name)) {
            throw new UnprocessableEntityHttpException('Un métier a besoin d’un nom : c’est ce que le menu affiche.');
        }

        $statut = PublicationStatus::tryFrom($vue->status);

        if (null === $statut) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Le statut « %s » n’existe pas : un métier est « %s » ou « %s », et rien d’autre.',
                $vue->status,
                PublicationStatus::Draft->value,
                PublicationStatus::Published->value,
            ));
        }

        try {
            $ligne = $ligne instanceof Trade
                ? $this->redactionMetier->mettreAJour($ligne, $vue->slug, $vue->name, $vue->searchTitle, $vue->lead, $vue->position, $statut, $vue->activities)
                : $this->redactionMetier->creer($vue->code, $vue->slug, $vue->name, $vue->searchTitle, $vue->lead, $vue->position, $statut, $vue->activities);
        } catch (UnknownActivityException $refus) {
            throw new UnprocessableEntityHttpException($refus->getMessage(), $refus);
        } catch (PublishedSlugIsFrozenException|TradeAlreadyExistsException $refus) {
            throw new ConflictHttpException($refus->getMessage(), $refus);
        }

        /*
         * On relit ce qui a été rangé plutôt que de rendre ce qui a été reçu — et ici ce n'est pas
         * une précaution de forme : les MODULES sont déduits des activités, ils n'étaient pas dans
         * la requête. Renvoyer la vue reçue afficherait une liste de modules vide juste après
         * l'enregistrement, et la vraie au rechargement.
         */
        return $this->vueMetier->depuis($ligne);
    }

    private function ecrireLeBloc(EditorContentBlock $vue, \DateTimeImmutable $maintenant): EditorContentBlock
    {
        try {
            $this->blocs->enregistrer($vue->id, $vue->value ?? [], $maintenant);
        } catch (UnknownBlockException $refus) {
            throw new UnprocessableEntityHttpException($refus->getMessage(), $refus);
        }

        // On relit ce qui a été rangé plutôt que de rendre ce qui a été reçu : la normalisation a pu
        // écarter une carte vide ou couper des espaces, et l'écran doit voir l'état réel.
        foreach ($this->blocs->pourLAdministration() as $ligne) {
            if ($ligne['key'] === $vue->id) {
                $vue->type = $ligne['type'];
                $vue->label = $ligne['label'];
                $vue->help = $ligne['help'];
                $vue->groupe = $ligne['groupe'];
                $vue->value = $ligne['value'];
                break;
            }
        }

        return $vue;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function ecrireLaRubrique(EditorBlogCategory $vue, Operation $operation, array $uriVariables): ?EditorBlogCategory
    {
        $rubrique = isset($uriVariables['id'])
            ? $this->em->getRepository(BlogCategory::class)->find($this->uuid($uriVariables['id']))
            : new BlogCategory();

        if (!$rubrique instanceof BlogCategory) {
            throw new NotFoundHttpException('Cette rubrique n’existe pas.');
        }

        if ($operation instanceof Delete) {
            // Les articles ne partent PAS avec : la colonne repasse à NULL (`onDelete: SET NULL`).
            $this->em->remove($rubrique);
            $this->em->flush();

            return null;
        }

        $nom = trim($vue->name);

        if ('' === $nom) {
            throw new UnprocessableEntityHttpException('Une rubrique a besoin d’un nom.');
        }

        $rubrique->setName($nom)->setDescription($vue->description);

        if ('' === $rubrique->getSlug()) {
            $rubrique->setSlug($this->slugRubrique('' !== trim($vue->slug) ? $vue->slug : $nom));
        }

        $this->em->persist($rubrique);
        $this->em->flush();

        $vue->id = $rubrique->getId()->toRfc4122();
        $vue->slug = $rubrique->getSlug();

        return $vue;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function ecrireLArticle(EditorBlogPost $vue, Operation $operation, array $uriVariables, \DateTimeImmutable $maintenant): ?EditorBlogPost
    {
        $article = isset($uriVariables['id'])
            ? $this->em->getRepository(BlogPost::class)->find($this->uuid($uriVariables['id']))
            : null;

        if (isset($uriVariables['id']) && !$article instanceof BlogPost) {
            throw new NotFoundHttpException('Cet article n’existe pas.');
        }

        if ($operation instanceof Delete) {
            \assert($article instanceof BlogPost);
            $this->em->remove($article);
            $this->em->flush();

            return null;
        }

        if ('' === trim($vue->title)) {
            throw new UnprocessableEntityHttpException('Un article a besoin d’un titre.');
        }

        $champs = [
            'title' => $vue->title,
            'excerpt' => $vue->excerpt,
            'body' => $vue->body,
            'coverUrl' => $vue->coverUrl,
            'coverAlt' => $vue->coverAlt,
            'authorName' => $vue->authorName,
            'metaDescription' => $vue->metaDescription,
            'categoryId' => $vue->categoryId,
            'status' => $vue->status,
            'publishedAt' => $vue->publishedAt,
            'slug' => $vue->slug,
        ];

        try {
            $article = null === $article
                ? $this->redaction->creer($champs, $maintenant)
                : $this->redaction->modifier($article, $champs, $maintenant);
        } catch (PublishedSlugIsFrozenException $refus) {
            // 409 et pas 422 : la demande est bien formée, c'est l'état de la ressource qui s'y
            // oppose. L'écran doit pouvoir le dire sans faire perdre ce qui vient d'être écrit.
            throw new ConflictHttpException($refus->getMessage(), $refus);
        }

        $vue->id = $article->getId()->toRfc4122();
        $vue->slug = $article->getSlug();
        $vue->body = $article->getBody();
        $vue->status = $article->getStatus()->value;
        $vue->publishedAt = $article->getPublishedAt()?->format(\DateTimeInterface::ATOM);
        $vue->visible = $article->isVisible($maintenant);
        $vue->updatedAt = $article->getUpdatedAt()->format(\DateTimeInterface::ATOM);

        return $vue;
    }

    private function slugRubrique(string $souhaite): string
    {
        $base = $this->slugs->unique($souhaite);

        // `SlugGenerator` compte les articles, pas les rubriques : deux espaces d'adresses distincts
        // (`/blog/{slug}` et `/blog/rubrique/{slug}`), donc aucune raison qu'un nom de rubrique se
        // fasse suffixer parce qu'un article porte le même. On lui emprunte la normalisation et on
        // règle l'unicité ici, où elle a un sens.
        $candidat = $base;
        $suffixe = 1;

        while (null !== $this->em->getRepository(BlogCategory::class)->findOneBy(['slug' => $candidat])) {
            ++$suffixe;
            $candidat = $base.'-'.$suffixe;
        }

        return $candidat;
    }

    private function uuid(mixed $brut): Uuid
    {
        if (!\is_string($brut) || !Uuid::isValid($brut)) {
            throw new NotFoundHttpException();
        }

        return Uuid::fromString($brut);
    }
}
