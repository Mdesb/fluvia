<?php

declare(strict_types=1);

namespace App\Fonctionnalite\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Fonctionnalite\State\CatalogueCapaciteProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * GET /fonctionnalites/catalogue : référentiel en lecture des capacités connues du socle. Donnée de
 * référence (pas de tenant) — accessible à tout utilisateur authentifié, afin que toute UI (back-office
 * ou verticale) puisse construire ses écrans d'activation sans permission dédiée.
 */
#[ApiResource(
    shortName: 'CapaciteCatalogue',
    operations: [
        new GetCollection(
            uriTemplate: '/fonctionnalites/catalogue',
            paginationEnabled: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: CatalogueCapaciteProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['capacite_catalogue:read']],
)]
final class CapaciteCatalogueItem
{
    #[ApiProperty(identifier: true)]
    #[Groups(['capacite_catalogue:read'])]
    public string $code = '';

    #[Groups(['capacite_catalogue:read'])]
    public string $libelle = '';

    #[Groups(['capacite_catalogue:read'])]
    public string $description = '';

    #[Groups(['capacite_catalogue:read'])]
    public string $categorie = '';

    /**
     * ⚠ CETTE CAPACITE EST-ELLE UNE VERTICALE D'ACTIVITE PLUTOT QU'UN MODULE ACHETABLE ?
     *
     * « Padel, ce n'est pas un module » — Maxime, 01/09. `padel`, `piscine`, `sport`, `patinoire`
     * et `musee` sont des valeurs de l'enum `Metier` : des PRESETS qui activent chacun un jeu de
     * capacites. C'est ce qu'un etablissement EST, pas ce qu'il ajoute a la carte.
     *
     * Le serveur le dit pour que la boutique n'ait pas a le deviner. Filtrer cote frontal sur la
     * categorie « metier » reconstruirait une regle metier a partir d'une etiquette decorative.
     */
    #[Groups(['capacite_catalogue:read'])]
    public bool $estVerticale = false;
}
