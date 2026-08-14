<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sous-réseau / accès fédéré (US-L3-12) : regroupe plusieurs `EspaceAcces` partageant des règles
 * d'accès communes. La fédération est activable/désactivable (CA-13) ; désactivée, le franchissement
 * inter-entités est refusé. `droitsEligiblesRef` référence des UUID de Produit (M1), sans FK dure.
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_sous_reseau')]
#[ApiResource(
    shortName: 'SousReseau',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
        new Post(security: "is_granted('PERM', 'acces.gerer')"),
        new Patch(security: "is_granted('PERM', 'acces.gerer')"),
    ],
    normalizationContext: ['groups' => ['sous_reseau:read']],
    denormalizationContext: ['groups' => ['sous_reseau:write']],
)]
class SousReseau
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['sous_reseau:read', 'espace_acces:read', 'droit:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['sous_reseau:read', 'sous_reseau:write'])]
    private string $libelle = '';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['sous_reseau:read', 'sous_reseau:write'])]
    private bool $actif = false;

    /** @var Collection<int, EspaceAcces> */
    #[ORM\ManyToMany(targetEntity: EspaceAcces::class)]
    #[ORM\JoinTable(name: 'acces_sous_reseau_espace')]
    #[Groups(['sous_reseau:read', 'sous_reseau:write'])]
    private Collection $espaces;

    /** @var list<string>|null UUID de Produit (M1) éligibles à la reconnaissance mutuelle. */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['sous_reseau:read', 'sous_reseau:write'])]
    private ?array $droitsEligiblesRef = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['sous_reseau:read', 'sous_reseau:write'])]
    private ?int $seuilFmiAgrege = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    #[Groups(['sous_reseau:read', 'sous_reseau:write'])]
    private ?int $antiPassbackDelai = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->espaces = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

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

    /** @return Collection<int, EspaceAcces> */
    public function getEspaces(): Collection
    {
        return $this->espaces;
    }

    public function addEspace(EspaceAcces $espace): self
    {
        if (!$this->espaces->contains($espace)) {
            $this->espaces->add($espace);
        }

        return $this;
    }

    /** @return list<string>|null */
    public function getDroitsEligiblesRef(): ?array
    {
        return $this->droitsEligiblesRef;
    }

    /** @param list<string>|null $droitsEligiblesRef */
    public function setDroitsEligiblesRef(?array $droitsEligiblesRef): self
    {
        $this->droitsEligiblesRef = $droitsEligiblesRef;

        return $this;
    }

    public function getSeuilFmiAgrege(): ?int
    {
        return $this->seuilFmiAgrege;
    }

    public function setSeuilFmiAgrege(?int $seuilFmiAgrege): self
    {
        $this->seuilFmiAgrege = $seuilFmiAgrege;

        return $this;
    }

    public function getAntiPassbackDelai(): ?int
    {
        return $this->antiPassbackDelai;
    }

    public function setAntiPassbackDelai(?int $antiPassbackDelai): self
    {
        $this->antiPassbackDelai = $antiPassbackDelai;

        return $this;
    }
}
