<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Offre\Enum\Canal;
use App\Offre\State\SuppressionReferentielProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Type de tarif : dimension de la grille tarifaire (RG-M1-01). Sa visibilité par canal
 * restreint son apparition (RG-M1-07 / CA-15). Non supprimable s'il est utilisé (CA-9) :
 * la suppression est refusée (409) au profit de la désactivation (actif=false).
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_type_tarif')]
#[UniqueEntity(fields: ['nom'], message: 'Un type de tarif porte déjà ce nom.')]
#[ApiResource(
    shortName: 'TypeTarif',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
        new Post(security: "is_granted('PERM', 'offre.gerer')"),
        new Patch(security: "is_granted('PERM', 'offre.gerer')"),
        new Delete(
            security: "is_granted('PERM', 'offre.gerer')",
            processor: SuppressionReferentielProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['ref:read']],
    denormalizationContext: ['groups' => ['ref:write']],
)]
class TypeTarif
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ref:read', 'produit:read', 'grille:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['ref:read', 'ref:write', 'produit:read', 'grille:read'])]
    private string $nom = '';

    /** @var list<string> Canaux où ce tarif est visible (RG-M1-07). Vide = visible partout. */
    #[ORM\Column]
    #[Groups(['ref:read', 'ref:write', 'grille:read'])]
    private array $visibiliteCanal = [];

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['ref:read', 'ref:write'])]
    private int $ordreAffichage = 0;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['ref:read', 'ref:write'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    /** @return list<string> */
    public function getVisibiliteCanal(): array
    {
        return $this->visibiliteCanal;
    }

    /** @param list<string> $visibiliteCanal */
    public function setVisibiliteCanal(array $visibiliteCanal): self
    {
        $this->visibiliteCanal = array_values($visibiliteCanal);

        return $this;
    }

    /** Vrai si ce tarif est visible sur le canal donné (RG-M1-07). Vide = visible partout. */
    public function estVisibleSur(Canal $canal): bool
    {
        return $this->visibiliteCanal === [] || \in_array($canal->value, $this->visibiliteCanal, true);
    }

    public function getOrdreAffichage(): int
    {
        return $this->ordreAffichage;
    }

    public function setOrdreAffichage(int $ordreAffichage): self
    {
        $this->ordreAffichage = $ordreAffichage;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }
}
