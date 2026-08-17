<?php

declare(strict_types=1);

namespace App\Caution\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Caution\Enum\ModeRetenue;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Grille de retenue générique paramétrable par établissement (refactor du patron dupliqué par
 * `App\Padel\Entity\GrilleRetenueMateriel` et `App\Patinoire\Entity\GrilleRetenue`). `sousCible` est
 * une granularité optionnelle **opaque** sous la cible (ex. type d'article padel « raquette », ou
 * l'UUID d'un `ParcPatins` pour la patinoire) : une règle avec `sousCible` renseigné prime sur la
 * règle générale (`sousCible` NULL) pour le même `typeCible`/`motif` — même priorité que
 * `App\Patinoire\Service\ResolveurGrilleRetenueHandler` (résolue désormais par
 * `App\Caution\Service\GestionCaution::resoudreGrille()`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'caution_grille_retenue')]
#[ApiResource(
    shortName: 'CautionGrilleRetenue',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'caution.lire')"),
        new Get(security: "is_granted('PERM', 'caution.lire')"),
        new Post(security: "is_granted('PERM', 'caution.parametrer')"),
        new Patch(security: "is_granted('PERM', 'caution.parametrer')"),
    ],
    normalizationContext: ['groups' => ['caution_grille:read']],
    denormalizationContext: ['groups' => ['caution_grille:write']],
)]
class GrilleRetenue
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['caution_grille:read', 'retenue:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['caution_grille:read', 'caution_grille:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Groups(['caution_grille:read', 'caution_grille:write'])]
    private string $typeCible = '';

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['caution_grille:read', 'caution_grille:write'])]
    private ?string $sousCible = null;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    #[Groups(['caution_grille:read', 'caution_grille:write'])]
    private string $motif = '';

    #[ORM\Column(length: 20, enumType: ModeRetenue::class, options: ['default' => 'forfait'])]
    #[Groups(['caution_grille:read', 'caution_grille:write'])]
    private ModeRetenue $mode = ModeRetenue::Forfait;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    #[Groups(['caution_grille:read', 'caution_grille:write'])]
    private int $montantCentimes = 0;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['caution_grille:read', 'caution_grille:write'])]
    private bool $actif = true;

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

    public function getTypeCible(): string
    {
        return $this->typeCible;
    }

    public function setTypeCible(string $typeCible): self
    {
        $this->typeCible = $typeCible;

        return $this;
    }

    public function getSousCible(): ?string
    {
        return $this->sousCible;
    }

    public function setSousCible(?string $sousCible): self
    {
        $this->sousCible = $sousCible;

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

    public function getMode(): ModeRetenue
    {
        return $this->mode;
    }

    public function setMode(ModeRetenue $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getMontantCentimes(): int
    {
        return $this->montantCentimes;
    }

    public function setMontantCentimes(int $montantCentimes): self
    {
        $this->montantCentimes = $montantCentimes;

        return $this;
    }

    public function getMontantDecimal(): string
    {
        return Caution::centimesVersDecimal($this->montantCentimes);
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
