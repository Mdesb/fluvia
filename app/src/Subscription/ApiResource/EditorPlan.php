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
 * Une formule, vue et modifiée depuis l'administration de l'éditeur (ED-6).
 *
 * **Ressource dédiée, et non l'entité `Plan` exposée directement.** La première version exposait
 * l'entité ; le garde-fou de couverture de périmètre l'a refusée, et il avait deux raisons meilleures
 * que la mienne :
 *
 * 1. **Une entité exposée rend modifiable tout ce qu'elle sait écrire.** Ici, seuls cinq champs le
 *    sont. Ce qui n'est pas dans cette classe ne peut pas être touché par une requête, même mal
 *    formée, même demain quand l'entité aura gagné des colonnes.
 * 2. **Une entité exposée dont la collection ne se filtre pas est lisible d'un tenant à l'autre.**
 *    `Plan` n'a pas d'établissement — c'est un catalogue global, à dessein — donc rien ne pourrait la
 *    cloisonner si quelqu'un ajoutait une opération sans passer par le fournisseur. Une ressource
 *    dédiée n'a pas de collection Doctrine à filtrer : elle n'existe que par son fournisseur, qui
 *    porte le contrôle.
 *
 * L'exemption était offerte — le garde-fou proposait de documenter l'entité comme globale. Corriger
 * la conception coûtait une heure et supprime la question.
 */
#[ApiResource(
    shortName: 'EditorPlan',
    operations: [
        new GetCollection(uriTemplate: '/editor/catalog/plans', provider: EditorCatalogProvider::class),
        new Get(uriTemplate: '/editor/catalog/plans/{id}', provider: EditorCatalogProvider::class),
        new Post(uriTemplate: '/editor/catalog/plans', provider: EditorCatalogProvider::class, processor: EditorCatalogProcessor::class),
        new Patch(uriTemplate: '/editor/catalog/plans/{id}', provider: EditorCatalogProvider::class, processor: EditorCatalogProcessor::class),
        new Delete(uriTemplate: '/editor/catalog/plans/{id}', provider: EditorCatalogProvider::class, processor: EditorCatalogProcessor::class),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorPlan
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    /** Identifiant stable, porté par les contrats et les paniers déjà composés. */
    public string $code = '';

    public string $label = '';

    public int $monthlyPriceCents = 0;

    /**
     * Les capacités comprises dans le prix, par leur code technique.
     *
     * @var list<string>
     */
    public array $includedCapabilities = [];

    /** Retirer de la vente ne touche pas les abonnements en cours (RG-ED-06, même esprit). */
    public bool $active = true;
}
