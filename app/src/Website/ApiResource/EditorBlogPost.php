<?php

declare(strict_types=1);

namespace App\Website\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Website\State\EditorWebsiteProcessor;
use App\Website\State\EditorWebsiteProvider;

/**
 * Un article du blog, vu et modifié depuis l'administration de l'éditeur (ED-10).
 *
 * **Ressource dédiée, jamais l'entité.** Même raison qu'`EditorPlan` : ce qui n'est pas déclaré ici
 * ne peut pas être écrit par une requête, même mal formée, même le jour où l'entité aura gagné des
 * colonnes. Et `BlogPost` n'a pas d'établissement — c'est le site de l'éditeur — donc rien ne
 * pourrait la cloisonner si quelqu'un lui ajoutait une opération sans passer par le fournisseur.
 *
 * ⚠ **`body` PART D'ICI EN HTML BRUT ET ARRIVE ASSAINI.** Le nettoyage se fait à l'écriture, dans
 * {@see \App\Website\Service\BodySanitizer} ; ce que rend la lecture est donc déjà le HTML servi au
 * public. Un rédacteur qui colle un `<script>` le verra disparaître à l'enregistrement — c'est
 * voulu, et c'est visible, contrairement à un nettoyage au rendu que personne ne constate.
 */
#[ApiResource(
    shortName: 'EditorBlogPost',
    operations: [
        new GetCollection(uriTemplate: '/editor/website/posts', provider: EditorWebsiteProvider::class),
        // ⚠ @sans-ecran: aucune interface n'appelle cette lecture d'item, et pourtant elle est
        // INDISPENSABLE : API Platform fabrique l'identifiant (@id) de chaque element d'une
        // collection a partir d'une route d'item. Sans elle, c'est la COLLECTION qui echoue —
        // « Unable to generate an IRI for the item of type … » — et le symptome ne designe donc pas
        // ce qui manque. Mesure faite le 04/09 en la retirant.
        new Get(uriTemplate: '/editor/website/posts/{id}', provider: EditorWebsiteProvider::class),
        new Post(uriTemplate: '/editor/website/posts', provider: EditorWebsiteProvider::class, processor: EditorWebsiteProcessor::class),
        new Patch(uriTemplate: '/editor/website/posts/{id}', provider: EditorWebsiteProvider::class, processor: EditorWebsiteProcessor::class),
        new Delete(uriTemplate: '/editor/website/posts/{id}', provider: EditorWebsiteProvider::class, processor: EditorWebsiteProcessor::class),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorBlogPost
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    /**
     * L'adresse publique.
     *
     * Laissée vide à la création, elle se dérive du titre. ⚠ Une fois l'article publié, elle est
     * **gelée** : la modifier est refusée, parce qu'un lien partagé ne se répare pas de notre côté.
     */
    public string $slug = '';

    public string $title = '';

    /** Le chapô : lu dans la liste, et servi aux moteurs à défaut de `metaDescription`. */
    public string $excerpt = '';

    /** Le corps, en HTML. Assaini à l'enregistrement — voir le commentaire de classe. */
    public string $body = '';

    public ?string $coverUrl = null;

    public ?string $coverAlt = null;

    /** `draft` ou `published`. */
    public string $status = 'draft';

    /**
     * La date à partir de laquelle l'article devient public, en ISO 8601.
     *
     * Dans le futur, c'est une planification : rien n'a besoin de tourner ce jour-là, la lecture
     * publique compare simplement la date à l'heure de la requête.
     */
    public ?string $publishedAt = null;

    public ?string $categoryId = null;

    public ?string $categoryName = null;

    public ?string $authorName = null;

    public ?string $metaDescription = null;

    /**
     * L'article est-il servi au public **en ce moment** ?
     *
     * Calculé par le serveur, et rendu tel quel : sans lui, l'écran devrait refaire lui-même la règle
     * « publié ET daté au passé », et le jour où elle change, deux endroits diraient deux choses.
     */
    public bool $visible = false;

    public ?string $updatedAt = null;
}
