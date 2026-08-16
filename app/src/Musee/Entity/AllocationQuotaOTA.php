<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Allocation de quota OTA (US-MUSEE-07, RG-MUS-04) : **sous-ensemble** du quota du créneau, pas un
 * stock séparé — une vente OTA décrémente le **même inventaire réel** que la vente directe (anti
 * sur-vente).
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_allocation_quota_ota')]
#[ORM\UniqueConstraint(name: 'uniq_allocation_partenaire_creneau', columns: ['partenaire_id', 'creneau_id'])]
#[ApiResource(
    shortName: 'MuseeAllocationQuotaOTA',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(security: "is_granted('PERM', 'musee.gerer_ota')"),
        new Patch(security: "is_granted('PERM', 'musee.gerer_ota')"),
    ],
    normalizationContext: ['groups' => ['allocation:read']],
    denormalizationContext: ['groups' => ['allocation:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['partenaire' => 'exact', 'creneau' => 'exact'])]
class AllocationQuotaOTA
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['allocation:read', 'resa_ota:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PartenaireOTA::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['allocation:read', 'allocation:write'])]
    private ?PartenaireOTA $partenaire = null;

    #[ORM\ManyToOne(targetEntity: Creneau::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['allocation:read', 'allocation:write'])]
    private ?Creneau $creneau = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    #[Groups(['allocation:read', 'allocation:write'])]
    private int $quotaAlloue = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['allocation:read'])]
    private int $quotaConsomme = 0;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['allocation:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPartenaire(): ?PartenaireOTA
    {
        return $this->partenaire;
    }

    public function setPartenaire(?PartenaireOTA $partenaire): self
    {
        $this->partenaire = $partenaire;
        if ($partenaire !== null) {
            $this->etablissement = $partenaire->getEtablissement();
        }

        return $this;
    }

    public function getCreneau(): ?Creneau
    {
        return $this->creneau;
    }

    public function setCreneau(?Creneau $creneau): self
    {
        $this->creneau = $creneau;

        return $this;
    }

    public function getQuotaAlloue(): int
    {
        return $this->quotaAlloue;
    }

    public function setQuotaAlloue(int $quotaAlloue): self
    {
        $this->quotaAlloue = $quotaAlloue;

        return $this;
    }

    public function getQuotaConsomme(): int
    {
        return $this->quotaConsomme;
    }

    public function setQuotaConsomme(int $quotaConsomme): self
    {
        $this->quotaConsomme = $quotaConsomme;

        return $this;
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

    public function estEpuisee(): bool
    {
        return $this->quotaConsomme >= $this->quotaAlloue;
    }
}
