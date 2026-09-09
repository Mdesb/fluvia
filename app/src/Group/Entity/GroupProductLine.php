<?php

declare(strict_types=1);

namespace App\Group\Entity;

use App\Compta\Entity\TauxTva;
use App\Offre\Entity\Produit;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une ligne d'un forfait groupe (`GroupProduct`) : un **produit du catalogue** × une quantité, à un
 * prix et un taux de TVA. C'est ce qui rend un « produit groupe » composite — 5 audioguides, 3 entrées,
 * 5 visites — au lieu d'un montant unique.
 *
 * Le prix et la TVA sont **figés sur la ligne** (pas résolus par le moteur tarifaire) : les tarifs de
 * groupe sont le plus souvent négociés, et cela évite qu'un forfait enregistré dérive quand le tarif du
 * produit change. Le `produit` reste rattaché pour l'identité et la désignation portée au devis.
 *
 * Entité **imbriquée** dans `GroupProduct` (pas de ressource API propre) : cloisonnée par son forfait,
 * qui porte l'établissement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'group_product_line')]
class GroupProductLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['group_product:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GroupProduct::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?GroupProduct $groupProduct = null;

    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['group_product:read', 'group_product:write'])]
    private ?Produit $produit = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    #[Assert\Positive]
    #[Groups(['group_product:read', 'group_product:write'])]
    private int $quantite = 1;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['group_product:read', 'group_product:write'])]
    private string $prixUnitaireHT = '0.00';

    #[ORM\ManyToOne(targetEntity: TauxTva::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['group_product:read', 'group_product:write'])]
    private ?TauxTva $tauxTva = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGroupProduct(): ?GroupProduct
    {
        return $this->groupProduct;
    }

    public function setGroupProduct(?GroupProduct $groupProduct): self
    {
        $this->groupProduct = $groupProduct;

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
}
