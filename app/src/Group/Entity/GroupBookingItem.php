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
use App\Compta\Entity\TauxTva;
use App\Group\State\CreateGroupBookingItemProcessor;
use App\Offre\Entity\Produit;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un article du panier d'une réservation de groupe : un produit × une quantité, à son prix et sa TVA.
 * C'est ce que la facturation transforme en **une ligne de devis**.
 *
 * Deux origines, une même table (« les deux » côtés demandés) :
 *   • recopié d'un `GroupProduct` (forfait réutilisable) via `apply-product` — `source` renseigne lequel ;
 *   • ajouté **à la carte** directement sur la réservation — `source` nul.
 *
 * Cloisonné par sa `booking` (pas de colonne établissement) ; la création vérifie que la réservation
 * appartient à l'établissement actif (RG-SOCLE-05).
 */
#[ORM\Entity]
#[ORM\Table(name: 'group_booking_item')]
#[ApiResource(
    shortName: 'GroupBookingItem',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'group.read')"),
        new Post(security: "is_granted('PERM', 'group.manage')", processor: CreateGroupBookingItemProcessor::class),
        new Patch(security: "is_granted('PERM', 'group.manage')"),
        new Delete(security: "is_granted('PERM', 'group.manage')"),
    ],
    normalizationContext: ['groups' => ['group_booking_item:read']],
    denormalizationContext: ['groups' => ['group_booking_item:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['booking' => 'exact'])]
class GroupBookingItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['group_booking_item:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GroupBooking::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['group_booking_item:read', 'group_booking_item:write'])]
    private ?GroupBooking $booking = null;

    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['group_booking_item:read', 'group_booking_item:write'])]
    private ?Produit $produit = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    #[Assert\Positive]
    #[Groups(['group_booking_item:read', 'group_booking_item:write'])]
    private int $quantite = 1;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['group_booking_item:read', 'group_booking_item:write'])]
    private string $prixUnitaireHT = '0.00';

    #[ORM\ManyToOne(targetEntity: TauxTva::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['group_booking_item:read', 'group_booking_item:write'])]
    private ?TauxTva $tauxTva = null;

    /** Forfait d'origine si l'article vient d'un `apply-product` ; nul si ajouté à la carte. */
    #[ORM\ManyToOne(targetEntity: GroupProduct::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['group_booking_item:read'])]
    private ?GroupProduct $source = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBooking(): ?GroupBooking
    {
        return $this->booking;
    }

    public function setBooking(?GroupBooking $booking): self
    {
        $this->booking = $booking;

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

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): self
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getPrixUnitaireHT(): string
    {
        return $this->prixUnitaireHT;
    }

    public function setPrixUnitaireHT(string $prixUnitaireHT): self
    {
        $this->prixUnitaireHT = $prixUnitaireHT;

        return $this;
    }

    public function getTauxTva(): ?TauxTva
    {
        return $this->tauxTva;
    }

    public function setTauxTva(?TauxTva $tauxTva): self
    {
        $this->tauxTva = $tauxTva;

        return $this;
    }

    public function getSource(): ?GroupProduct
    {
        return $this->source;
    }

    public function setSource(?GroupProduct $source): self
    {
        $this->source = $source;

        return $this;
    }
}
