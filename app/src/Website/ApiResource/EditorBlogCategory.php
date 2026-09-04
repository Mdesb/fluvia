<?php

declare(strict_types=1);

namespace App\Website\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Website\State\EditorWebsiteProcessor;
use App\Website\State\EditorWebsiteProvider;

/**
 * Une rubrique du blog, côté administration (ED-10).
 *
 * ⚠ **La supprimer ne supprime pas ses articles** : ils repassent simplement sans rubrique. C'est la
 * base qui le garantit (`onDelete: SET NULL`), pas une précaution de l'écran — un écran se contourne
 * par un appel direct, une contrainte de base ne se contourne pas.
 */
#[ApiResource(
    shortName: 'EditorBlogCategory',
    operations: [
        new GetCollection(uriTemplate: '/editor/website/categories', provider: EditorWebsiteProvider::class),
        new Post(uriTemplate: '/editor/website/categories', provider: EditorWebsiteProvider::class, processor: EditorWebsiteProcessor::class),
        new Patch(uriTemplate: '/editor/website/categories/{id}', provider: EditorWebsiteProvider::class, processor: EditorWebsiteProcessor::class),
        new Delete(uriTemplate: '/editor/website/categories/{id}', provider: EditorWebsiteProvider::class, processor: EditorWebsiteProcessor::class),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorBlogCategory
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    public string $slug = '';

    public string $name = '';

    public ?string $description = null;

    /** Combien d'articles la portent — pour que supprimer une rubrique ne soit pas un geste aveugle. */
    public int $postCount = 0;
}
