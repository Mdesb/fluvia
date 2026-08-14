<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Journal d'une conversion de type assistée (RG-M1-11 / CA-13) : ancien type, nouveau type,
 * auteur, date, mapping des champs (conservés/ajoutés/abandonnés). Append-only, complète
 * l'EntreeAudit du socle. Aucune écriture via l'API.
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_conversion_type')]
#[ApiResource(
    shortName: 'ConversionType',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
    ],
    normalizationContext: ['groups' => ['conv:read']],
)]
class ConversionType
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['conv:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['conv:read'])]
    private ?Produit $produit = null;

    #[ORM\ManyToOne(targetEntity: TypeProduit::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['conv:read'])]
    private ?TypeProduit $ancienType = null;

    #[ORM\ManyToOne(targetEntity: TypeProduit::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['conv:read'])]
    private ?TypeProduit $nouveauType = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['conv:read'])]
    private ?string $auteur = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['conv:read'])]
    private \DateTimeImmutable $dateHeure;

    /** @var array<string, mixed> {conserves:[], ajoutes:[], abandonnes:[]}. */
    #[ORM\Column]
    #[Groups(['conv:read'])]
    private array $mapping = [];

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateHeure = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): self
    {
        $this->produit = $produit;

        return $this;
    }

    public function getAncienType(): ?TypeProduit
    {
        return $this->ancienType;
    }

    public function setAncienType(?TypeProduit $ancienType): self
    {
        $this->ancienType = $ancienType;

        return $this;
    }

    public function getNouveauType(): ?TypeProduit
    {
        return $this->nouveauType;
    }

    public function setNouveauType(?TypeProduit $nouveauType): self
    {
        $this->nouveauType = $nouveauType;

        return $this;
    }

    public function getAuteur(): ?string
    {
        return $this->auteur;
    }

    public function setAuteur(?string $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getDateHeure(): \DateTimeImmutable
    {
        return $this->dateHeure;
    }

    /** @return array<string, mixed> */
    public function getMapping(): array
    {
        return $this->mapping;
    }

    /** @param array<string, mixed> $mapping */
    public function setMapping(array $mapping): self
    {
        $this->mapping = $mapping;

        return $this;
    }
}
