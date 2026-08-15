<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Relance d'un casier non restitué (US-L6-09) : bascule l'état à « en retard », amorce le délai de forçage. */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_relance_casier')]
#[ApiResource(
    shortName: 'RelanceCasier',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
    ],
    normalizationContext: ['groups' => ['relance:read']],
)]
class RelanceCasier
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['relance:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Casier::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['relance:read'])]
    private ?Casier $casier = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['relance:read'])]
    private \DateTimeImmutable $dateRelance;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Positive]
    #[Groups(['relance:read'])]
    private int $delaiForcageJours;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateRelance = new \DateTimeImmutable();
        $this->delaiForcageJours = 3;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCasier(): ?Casier
    {
        return $this->casier;
    }

    public function setCasier(?Casier $casier): self
    {
        $this->casier = $casier;

        return $this;
    }

    public function getDateRelance(): \DateTimeImmutable
    {
        return $this->dateRelance;
    }

    public function setDateRelance(\DateTimeImmutable $dateRelance): self
    {
        $this->dateRelance = $dateRelance;

        return $this;
    }

    public function getDelaiForcageJours(): int
    {
        return $this->delaiForcageJours;
    }

    public function setDelaiForcageJours(int $delaiForcageJours): self
    {
        $this->delaiForcageJours = $delaiForcageJours;

        return $this;
    }

    /** Vrai si le délai de forçage est dépassé à la date donnée (CA-9). */
    public function forcageAutoriseA(\DateTimeImmutable $date): bool
    {
        return $date >= $this->dateRelance->modify(sprintf('+%d days', $this->delaiForcageJours));
    }
}
