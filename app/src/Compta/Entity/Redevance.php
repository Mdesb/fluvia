<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Redevance DSP — **point d'extension non implémenté** (§7.6 du plan), rattachée à un `Rad`.
 * Pas de ressource API dédiée (exposée via `Rad`, lui-même hors périmètre L4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_redevance')]
class Redevance
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Rad::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Rad $rad = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $formuleContractuelle = null;

    #[ORM\Column(nullable: true)]
    private ?int $assietteCentimes = null;

    #[ORM\Column(nullable: true)]
    private ?int $montantCentimes = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRad(): ?Rad
    {
        return $this->rad;
    }

    public function setRad(?Rad $rad): self
    {
        $this->rad = $rad;

        return $this;
    }

    public function getFormuleContractuelle(): ?string
    {
        return $this->formuleContractuelle;
    }

    public function setFormuleContractuelle(?string $formuleContractuelle): self
    {
        $this->formuleContractuelle = $formuleContractuelle;

        return $this;
    }

    public function getAssietteCentimes(): ?int
    {
        return $this->assietteCentimes;
    }

    public function setAssietteCentimes(?int $assietteCentimes): self
    {
        $this->assietteCentimes = $assietteCentimes;

        return $this;
    }

    public function getMontantCentimes(): ?int
    {
        return $this->montantCentimes;
    }

    public function setMontantCentimes(?int $montantCentimes): self
    {
        $this->montantCentimes = $montantCentimes;

        return $this;
    }
}
