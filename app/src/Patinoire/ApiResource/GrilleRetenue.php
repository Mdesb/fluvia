<?php

declare(strict_types=1);

namespace App\Patinoire\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Patinoire\Enum\ModeRetenue;
use App\Patinoire\Enum\MotifRetenue;
use App\Patinoire\State\GrilleRetenueProcessor;
use App\Patinoire\State\GrilleRetenueProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Grille de retenue paramétrable par établissement (décision actée « patins non rendus/cassés »,
 * US-PATIN-04, §4.4). **Fine délégation** (refactor caution générique) : ressource non-Doctrine,
 * entièrement portée par `App\Caution\Entity\GrilleRetenue` (cible `patinoire.patins`, sous-cible
 * l'UUID du `ParcPatins`) — même patron que `App\Padel\ApiResource\GrilleRetenueMateriel`. Contrat
 * API inchangé (`/api/patinoire_grille_retenues`). ⚠ Limitation connue : le `SearchFilter` Doctrine
 * (motif/parcPatins/actif/etablissement) de l'ancienne entité n'est pas reproduit sur cette ressource
 * non-ORM (aucun test ne l'exerçait) — filtrage GET collection à réintroduire manuellement si besoin.
 */
#[ApiResource(
    shortName: 'PatinoireGrilleRetenue',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'patinoire.lire')", provider: GrilleRetenueProvider::class),
        new Get(security: "is_granted('PERM', 'patinoire.lire')", provider: GrilleRetenueProvider::class),
        new Post(security: "is_granted('PERM', 'patinoire.configurer')", processor: GrilleRetenueProcessor::class),
        new Patch(security: "is_granted('PERM', 'patinoire.configurer')", provider: GrilleRetenueProvider::class, processor: GrilleRetenueProcessor::class),
    ],
    normalizationContext: ['groups' => ['grille_retenue:read']],
    denormalizationContext: ['groups' => ['grille_retenue:write']],
)]
final class GrilleRetenue
{
    #[ApiProperty(identifier: true)]
    #[Groups(['grille_retenue:read', 'retenue:read'])]
    public string $id = '';

    #[Assert\NotBlank]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    public ?string $etablissement = null;

    #[Assert\NotNull]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    public ?MotifRetenue $motif = null;

    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    public ?ModeRetenue $mode = ModeRetenue::Forfait;

    #[Assert\NotBlank]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    public string $montantOuTaux = '0.00';

    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    public ?string $parcPatins = null;

    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    public bool $actif = true;
}
