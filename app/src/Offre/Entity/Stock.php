<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Stock d'un produit : dédié (disponibilité propre) ou partagé (rattaché à un Pool, RG-M1-10).
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_stock')]
class Stock
{
    public const TYPE_DEDIE = 'dedie';
    public const TYPE_PARTAGE = 'partage';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['produit:read', 'stock:read'])]
    private Uuid $id;

    #[ORM\Column(length: 12, options: ['default' => 'dedie'])]
    #[Groups(['produit:read', 'produit:write', 'stock:read', 'stock:write'])]
    private string $type = self::TYPE_DEDIE;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['produit:read', 'produit:write', 'stock:read', 'stock:write'])]
    private int $disponibilite = 0;

    #[ORM\ManyToOne(targetEntity: Pool::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['produit:read', 'produit:write', 'stock:read', 'stock:write'])]
    private ?Pool $pool = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

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

    public function getPool(): ?Pool
    {
        return $this->pool;
    }

    public function setPool(?Pool $pool): self
    {
        $this->pool = $pool;

        return $this;
    }

    /** Disponibilité effective : celle du pool si partagé, sinon la disponibilité propre (RG-M1-10). */
    public function disponibiliteEffective(): int
    {
        if ($this->type === self::TYPE_PARTAGE && $this->pool !== null) {
            return $this->pool->getDisponibilite();
        }

        return $this->disponibilite;
    }
}
