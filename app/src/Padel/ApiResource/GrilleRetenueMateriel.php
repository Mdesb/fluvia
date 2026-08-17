<?php

declare(strict_types=1);

namespace App\Padel\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Padel\State\GrilleRetenueMaterielProcessor;
use App\Padel\State\GrilleRetenueMaterielProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Grille de retenue paramétrable pour une caution matériel non restituée (§4.7, par analogie
 * patinoire, non explicitement décrite pour le padel). **Fine délégation** (refactor caution
 * générique) : ressource non-Doctrine, entièrement portée par `App\Caution\Entity\GrilleRetenue`
 * (cible `padel.materiel`, sous-cible `typeArticle`) — même patron que
 * `App\Piscine\ApiResource\PossEtatLive`/`App\Boutique\ApiResource\CatalogueVitrine`. Contrat API
 * inchangé (`/api/padel_grille_retenue_materiels`).
 */
#[ApiResource(
    shortName: 'PadelGrilleRetenueMateriel',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')", provider: GrilleRetenueMaterielProvider::class),
        new Get(security: "is_granted('PERM', 'padel.lire')", provider: GrilleRetenueMaterielProvider::class),
        new Post(security: "is_granted('PERM', 'padel.parametrer')", processor: GrilleRetenueMaterielProcessor::class),
        new Patch(security: "is_granted('PERM', 'padel.parametrer')", provider: GrilleRetenueMaterielProvider::class, processor: GrilleRetenueMaterielProcessor::class),
    ],
    normalizationContext: ['groups' => ['grille_retenue:read']],
    denormalizationContext: ['groups' => ['grille_retenue:write']],
)]
final class GrilleRetenueMateriel
{
    #[ApiProperty(identifier: true)]
    #[Groups(['grille_retenue:read'])]
    public string $id = '';

    #[Assert\NotBlank]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    public ?string $etablissement = null;

    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    public string $typeArticle = '';

    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    public string $motif = '';

    #[Assert\NotBlank]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    public string $montantRetenue = '0.00';
}
