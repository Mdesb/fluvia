<?php

declare(strict_types=1);

namespace App\Group\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Group\State\EstablishmentStampProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Forfait groupe (« produit groupe ») : un modèle **réutilisable** composé de plusieurs produits du
 * catalogue avec leurs quantités — p. ex. « Forfait scolaire » = 3 entrées + 5 audioguides + 5 visites.
 * On le compose une fois et on l'applique à autant de réservations qu'on veut
 * (`POST /group/bookings/{id}/apply-product`), qui recopie ses lignes en articles de la réservation.
 *
 * Complète le tarif « à la tête » : là où un `GroupBooking` se facturait en une ligne (effectif × prix),
 * un forfait le facture en **une ligne par produit**, chacune avec sa TVA.
 *
 * D41 — `etablissement` estampillé côté serveur, jamais dans le corps.
 */
#[ORM\Entity]
#[ORM\Table(name: 'group_product')]
#[ApiResource(
    shortName: 'GroupProduct',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'group.read')"),
        new Post(security: "is_granted('PERM', 'group.manage')", processor: EstablishmentStampProcessor::class),
        new Patch(security: "is_granted('PERM', 'group.manage')"),
        new Delete(security: "is_granted('PERM', 'group.manage')"),
    ],
    normalizationContext: ['groups' => ['group_product:read']],
    denormalizationContext: ['groups' => ['group_product:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['actif' => 'exact', 'label' => 'partial'])]
class GroupProduct
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['group_product:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['group_product:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Groups(['group_product:read', 'group_product:write'])]
    private string $label = '';

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['group_product:read', 'group_product:write'])]
    private bool $actif = true;

    /**
     * @var Collection<int, GroupProductLine>
     *
     * Imbriquées (cascade) comme `Formule::$servicesInclus` : l'adder pose la rétro-référence, sans
     * quoi la ligne serait persistée sans forfait (`group_product_id` NULL → 500).
     */
    #[ORM\OneToMany(mappedBy: 'groupProduct', targetEntity: GroupProductLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['group_product:read', 'group_product:write'])]
    private Collection $lines;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['group_product:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->lines = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

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

    /** @return Collection<int, GroupProductLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(GroupProductLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setGroupProduct($this);
        }

        return $this;
    }

    public function removeLine(GroupProductLine $line): self
    {
        if ($this->lines->removeElement($line) && $line->getGroupProduct() === $this) {
            $line->setGroupProduct(null);
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
