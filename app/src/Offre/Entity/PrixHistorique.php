<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Historisation d'un prix (US-L1-03) : append-only. Une modification de prix conserve la valeur
 * passée pour l'audit et n'est pas rétroactive. L'API n'expose que la lecture (pas d'écriture).
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_prix_historique')]
#[ApiResource(
    shortName: 'PrixHistorique',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
    ],
    normalizationContext: ['groups' => ['prix:read']],
)]
class PrixHistorique
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['prix:read', 'grille:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GrilleTarifaire::class, inversedBy: 'historique')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['prix:read'])]
    private ?GrilleTarifaire $grille = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['prix:read', 'grille:read'])]
    private ?string $valeur = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['prix:read', 'grille:read'])]
    private \DateTimeImmutable $dateEffet;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['prix:read', 'grille:read'])]
    private ?string $auteur = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateEffet = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGrille(): ?GrilleTarifaire
    {
        return $this->grille;
    }

    public function setGrille(?GrilleTarifaire $grille): self
    {
        $this->grille = $grille;

        return $this;
    }

    public function getValeur(): ?string
    {
        return $this->valeur;
    }

    public function setValeur(?string $valeur): self
    {
        $this->valeur = $valeur;

        return $this;
    }

    public function getDateEffet(): \DateTimeImmutable
    {
        return $this->dateEffet;
    }

    public function setDateEffet(\DateTimeImmutable $dateEffet): self
    {
        $this->dateEffet = $dateEffet;

        return $this;
    }

    public function getAuteur(): ?string
    {
        return $this->auteur;
    }

    public function setAuteur(?string $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }
}
