<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Boutique\State\IngestionVenteOtaProcessor;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Allocation de quota OTA générique (RG-M3-09, §0 décision n°7 du plan) : **plafond contractuel**,
 * pas un stock séparé — référence soit un `Produit` (vente simple), soit un `Creneau` (timed-entry),
 * jamais les deux ; le décrément réel emprunte le même chemin que la vente directe
 * (`DecrementStockHandler`/`JaugeCreneauGuard`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_allocation_quota_ota')]
#[ApiResource(
    shortName: 'BoutiqueAllocationQuotaOta',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'boutique.lire')"),
        new Get(security: "is_granted('PERM', 'boutique.lire')"),
        new Post(security: "is_granted('PERM', 'boutique.gerer_connecteur_ota')"),
        new Patch(security: "is_granted('PERM', 'boutique.gerer_connecteur_ota')"),
        new Post(
            uriTemplate: '/boutique/ota/ventes',
            read: false,
            input: false,
            security: "is_granted('PERM', 'boutique.gerer_connecteur_ota')",
            processor: IngestionVenteOtaProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['allocation_ota:read']],
    denormalizationContext: ['groups' => ['allocation_ota:write']],
)]
class AllocationQuotaOTA
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['allocation_ota:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PartenaireOTA::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['allocation_ota:read', 'allocation_ota:write'])]
    private ?PartenaireOTA $partenaire = null;

    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['allocation_ota:read', 'allocation_ota:write'])]
    private ?Produit $produit = null;

    #[ORM\ManyToOne(targetEntity: Creneau::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['allocation_ota:read', 'allocation_ota:write'])]
    private ?Creneau $creneau = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    #[Groups(['allocation_ota:read', 'allocation_ota:write'])]
    private int $quotaAlloue = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['allocation_ota:read'])]
    private int $quotaConsomme = 0;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
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
        $this->etablissement = $partenaire?->getEtablissement();

        return $this;
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
