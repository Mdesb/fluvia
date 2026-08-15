<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Sport\Enum\MomentRefusBadge;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Politique anti-impayés (1 par établissement, US-SPORT-05/06, RG-SPORT-01/02, décision actée §4.5).
 * Pilote `MoteurAntiImpayesHandler` : nombre de représentations, calendrier, moment du refus de badge
 * (paramétrable, y compris dès le 1ᵉʳ échec).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_politique_anti_impayes')]
#[ORM\UniqueConstraint(name: 'uniq_politique_etablissement', columns: ['etablissement_id'])]
#[ApiResource(
    shortName: 'PolitiqueAntiImpayes',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.lire')"),
        new Get(security: "is_granted('PERM', 'sport.lire')"),
        new Post(security: "is_granted('PERM', 'sport.parametrer')"),
        new Patch(security: "is_granted('PERM', 'sport.parametrer')"),
    ],
    normalizationContext: ['groups' => ['politique:read']],
    denormalizationContext: ['groups' => ['politique:write']],
)]
class PolitiqueAntiImpayes
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['politique:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['politique:read', 'politique:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    #[Assert\PositiveOrZero]
    #[Groups(['politique:read', 'politique:write'])]
    private int $nbRepresentationsMax = 1;

    /** @var list<int> Délais en jours après rejet, un par représentation. */
    #[ORM\Column]
    #[Groups(['politique:read', 'politique:write'])]
    private array $calendrierRepresentationJours = [5];

    #[ORM\Column(length: 32, enumType: MomentRefusBadge::class, options: ['default' => 'apres_representation_echouee'])]
    #[Groups(['politique:read', 'politique:write'])]
    private MomentRefusBadge $momentRefusBadge = MomentRefusBadge::ApresRepresentationEchouee;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['politique:read', 'politique:write'])]
    private ?int $nReprAvantBadge = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['politique:read', 'politique:write'])]
    private ?int $delaiAvantSuspensionContratJours = null;

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

    public function getNbRepresentationsMax(): int
    {
        return $this->nbRepresentationsMax;
    }

    public function setNbRepresentationsMax(int $nbRepresentationsMax): self
    {
        $this->nbRepresentationsMax = $nbRepresentationsMax;

        return $this;
    }

    /** @return list<int> */
    public function getCalendrierRepresentationJours(): array
    {
        return $this->calendrierRepresentationJours;
    }

    /** @param list<int> $calendrierRepresentationJours */
    public function setCalendrierRepresentationJours(array $calendrierRepresentationJours): self
    {
        $this->calendrierRepresentationJours = $calendrierRepresentationJours;

        return $this;
    }

    public function getMomentRefusBadge(): MomentRefusBadge
    {
        return $this->momentRefusBadge;
    }

    public function setMomentRefusBadge(MomentRefusBadge $momentRefusBadge): self
    {
        $this->momentRefusBadge = $momentRefusBadge;

        return $this;
    }

    public function getNReprAvantBadge(): ?int
    {
        return $this->nReprAvantBadge;
    }

    public function setNReprAvantBadge(?int $nReprAvantBadge): self
    {
        $this->nReprAvantBadge = $nReprAvantBadge;

        return $this;
    }

    public function getDelaiAvantSuspensionContratJours(): ?int
    {
        return $this->delaiAvantSuspensionContratJours;
    }

    public function setDelaiAvantSuspensionContratJours(?int $delaiAvantSuspensionContratJours): self
    {
        $this->delaiAvantSuspensionContratJours = $delaiAvantSuspensionContratJours;

        return $this;
    }
}
