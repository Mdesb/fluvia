<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Piscine\Enum\TypePublic;
use App\Piscine\State\CreneauPublicProcessor;
use App\Piscine\State\CreneauPublicRemoveProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Public affecté à un créneau bassin (RG-PISC-03, US-L6-06) : un même créneau horaire peut porter
 * plusieurs publics (grand public/scolaire/club), chacun sur des lignes précises et avec sa propre
 * jauge indépendante. `AffectationLigneGuard` refuse le chevauchement d'une ligne entre deux publics
 * (CA-6) ; `PossProrataCalculator` recalcule la jauge grand public à chaque écriture (CA-7).
 */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_creneau_public')]
#[ApiResource(
    shortName: 'CreneauPublic',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
        new Post(security: "is_granted('PERM', 'piscine.configurer')", processor: CreneauPublicProcessor::class),
        new Patch(security: "is_granted('PERM', 'piscine.configurer')", processor: CreneauPublicProcessor::class),
        new Delete(security: "is_granted('PERM', 'piscine.configurer')", processor: CreneauPublicRemoveProcessor::class),
    ],
    normalizationContext: ['groups' => ['creneau_public:read']],
    denormalizationContext: ['groups' => ['creneau_public:write']],
)]
class CreneauPublic
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['creneau_public:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CreneauBassin::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['creneau_public:read', 'creneau_public:write'])]
    private ?CreneauBassin $creneauBassin = null;

    #[ORM\Column(length: 12, enumType: TypePublic::class)]
    #[Assert\NotNull]
    #[Groups(['creneau_public:read', 'creneau_public:write'])]
    private ?TypePublic $typePublic = null;

    /** @var Collection<int, LigneEau> */
    #[ORM\ManyToMany(targetEntity: LigneEau::class)]
    #[ORM\JoinTable(name: 'piscine_creneau_public_ligne')]
    #[Assert\Count(min: 1, minMessage: 'Au moins une ligne doit être affectée (US-L6-06).')]
    #[Groups(['creneau_public:read', 'creneau_public:write'])]
    private Collection $lignes;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    #[Groups(['creneau_public:read', 'creneau_public:write'])]
    private int $jauge = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['creneau_public:read'])]
    private int $occupationCourante = 0;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->lignes = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreneauBassin(): ?CreneauBassin
    {
        return $this->creneauBassin;
    }

    public function setCreneauBassin(?CreneauBassin $creneauBassin): self
    {
        $this->creneauBassin = $creneauBassin;

        return $this;
    }

    public function getTypePublic(): ?TypePublic
    {
        return $this->typePublic;
    }

    public function setTypePublic(?TypePublic $typePublic): self
    {
        $this->typePublic = $typePublic;

        return $this;
    }

    /** @return Collection<int, LigneEau> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneEau $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
        }

        return $this;
    }

    public function removeLigne(LigneEau $ligne): self
    {
        $this->lignes->removeElement($ligne);

        return $this;
    }

    public function getJauge(): int
    {
        return $this->jauge;
    }

    public function setJauge(int $jauge): self
    {
        $this->jauge = $jauge;

        return $this;
    }

    public function getOccupationCourante(): int
    {
        return $this->occupationCourante;
    }

    public function setOccupationCourante(int $occupationCourante): self
    {
        $this->occupationCourante = max(0, $occupationCourante);

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->creneauBassin?->getEtablissement();
    }
}
