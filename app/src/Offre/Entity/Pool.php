<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Pool de stock partagé : sa disponibilité se décrémente pour tous les produits rattachés (RG-M1-10).
 * La concurrence à la vente relève de M2 (hors L1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_pool')]
class Pool
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['produit:read', 'stock:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Groups(['produit:read', 'stock:read', 'stock:write'])]
    private string $libelle = '';

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['produit:read', 'stock:read', 'stock:write'])]
    private int $disponibilite = 0;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getDisponibilite(): int
    {
        return $this->disponibilite;
    }

    public function setDisponibilite(int $disponibilite): self
    {
        $this->disponibilite = $disponibilite;

        return $this;
    }
}
