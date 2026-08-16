<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Grille de retenue paramétrable pour une caution matériel non restituée (§4.7, par analogie
 * patinoire, non explicitement décrite pour le padel).
 */
#[ORM\Entity]
#[ORM\Table(name: 'padel_grille_retenue_materiel')]
#[ApiResource(
    shortName: 'PadelGrilleRetenueMateriel',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
        new Post(security: "is_granted('PERM', 'padel.parametrer')"),
        new Patch(security: "is_granted('PERM', 'padel.parametrer')"),
    ],
    normalizationContext: ['groups' => ['grille_retenue:read']],
    denormalizationContext: ['groups' => ['grille_retenue:write']],
)]
class GrilleRetenueMateriel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['grille_retenue:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 40)]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    private string $typeArticle = '';

    #[ORM\Column(length: 20)]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    private string $motif = '';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['grille_retenue:read', 'grille_retenue:write'])]
    private string $montantRetenue = '0.00';

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    public function getTypeArticle(): string
    {
        return $this->typeArticle;
    }

    public function setTypeArticle(string $typeArticle): self
    {
        $this->typeArticle = $typeArticle;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getMontantRetenue(): string
    {
        return $this->montantRetenue;
    }

    public function setMontantRetenue(string $montantRetenue): self
    {
        $this->montantRetenue = $montantRetenue;

        return $this;
    }
}
