<?php

declare(strict_types=1);

namespace App\Personnel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Acces\Entity\EspaceAcces;
use App\Personnel\Enum\ModeHoraireBadge;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Portée d'accès d'un badge staff (RG-PERSO-06/07, §4.7 spec) : espaces autorisés (déclaratif dans ce
 * lot — décision n°5 du plan, `ValidationPassageHandler` n'applique aucune restriction fine par
 * espace) et mode horaire (`shifts_uniquement` ou `permanent`). Créée en side-effect de l'émission du
 * badge (`EmissionBadgeStaffHandler`), pas de `Post` direct.
 */
#[ORM\Entity]
#[ORM\Table(name: 'personnel_portee_acces')]
#[ORM\UniqueConstraint(name: 'uniq_portee_badge_staff', columns: ['badge_staff_id'])]
#[ApiResource(
    shortName: 'PorteeAccesEmploye',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'personnel.lire')"),
        new Get(security: "is_granted('PERM', 'personnel.lire')"),
    ],
    normalizationContext: ['groups' => ['portee_acces:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['badgeStaff' => 'exact'])]
class PorteeAccesEmploye
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['portee_acces:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: BadgeStaff::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['portee_acces:read'])]
    private ?BadgeStaff $badgeStaff = null;

    /** @var Collection<int, EspaceAcces> */
    #[ORM\ManyToMany(targetEntity: EspaceAcces::class)]
    #[ORM\JoinTable(name: 'personnel_portee_acces_espace')]
    #[Groups(['portee_acces:read'])]
    private Collection $espacesAutorises;

    #[ORM\Column(length: 18, enumType: ModeHoraireBadge::class)]
    #[Groups(['portee_acces:read'])]
    private ?ModeHoraireBadge $modeHoraire = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['portee_acces:read'])]
    private ?int $margeAvantApres = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->espacesAutorises = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBadgeStaff(): ?BadgeStaff
    {
        return $this->badgeStaff;
    }

    public function setBadgeStaff(?BadgeStaff $badgeStaff): self
    {
        $this->badgeStaff = $badgeStaff;

        return $this;
    }

    /** @return Collection<int, EspaceAcces> */
    public function getEspacesAutorises(): Collection
    {
        return $this->espacesAutorises;
    }

    public function addEspaceAutorise(EspaceAcces $espace): self
    {
        if (!$this->espacesAutorises->contains($espace)) {
            $this->espacesAutorises->add($espace);
        }

        return $this;
    }

    public function getModeHoraire(): ?ModeHoraireBadge
    {
        return $this->modeHoraire;
    }

    public function setModeHoraire(?ModeHoraireBadge $modeHoraire): self
    {
        $this->modeHoraire = $modeHoraire;

        return $this;
    }

    public function getMargeAvantApres(): ?int
    {
        return $this->margeAvantApres;
    }

    public function setMargeAvantApres(?int $margeAvantApres): self
    {
        $this->margeAvantApres = $margeAvantApres;

        return $this;
    }
}
