<?php

declare(strict_types=1);

namespace App\Subscription\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Subscription\State\EditorCatalogProcessor;
use App\Subscription\State\EditorCatalogProvider;

/**
 * Une option en vente, vue et modifiée depuis l'administration de l'éditeur (ED-6).
 *
 * Même raison qu'{@see EditorPlan} de ne pas exposer l'entité : ce qui n'est pas déclaré ici n'est
 * pas modifiable par une requête, et une ressource dédiée n'a pas de collection Doctrine qu'on
 * pourrait lire d'un tenant à l'autre.
 *
 * `capability` est le code d'un module réel. Le serveur refuse un code que le catalogue technique ne
 * connaît pas : on ne met pas en vente ce qu'on ne saura pas livrer (RG-ED-03).
 */
#[ApiResource(
    shortName: 'EditorPlanOption',
    operations: [
        new GetCollection(uriTemplate: '/editor/catalog/options', provider: EditorCatalogProvider::class),
        new Get(uriTemplate: '/editor/catalog/options/{id}', provider: EditorCatalogProvider::class),
        new Post(uriTemplate: '/editor/catalog/options', provider: EditorCatalogProvider::class, processor: EditorCatalogProcessor::class),
        new Patch(uriTemplate: '/editor/catalog/options/{id}', provider: EditorCatalogProvider::class, processor: EditorCatalogProcessor::class),
        new Delete(uriTemplate: '/editor/catalog/options/{id}', provider: EditorCatalogProvider::class, processor: EditorCatalogProcessor::class),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorPlanOption
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    public string $capability = '';

    public string $label = '';

    public int $monthlyPriceCents = 0;

    public bool $active = true;
}
