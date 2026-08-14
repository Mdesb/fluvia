<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Liste de révocation embarquée par contrôleur (RG-ACC-07, US-L3-07/09) : versionnée, propagée à la
 * prochaine synchro. Un support présent dans `supportsBloques` est refusé même hors-ligne.
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_liste_revocation')]
#[ORM\UniqueConstraint(name: 'uniq_revocation_controleur_version', columns: ['controleur_id', 'version'])]
#[ApiResource(
    shortName: 'ListeRevocation',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
    ],
    normalizationContext: ['groups' => ['revocation:read']],
)]
class ListeRevocation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['revocation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Controleur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['revocation:read'])]
    private ?Controleur $controleur = null;

    #[ORM\Column]
    #[Groups(['revocation:read'])]
    private int $version = 1;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    #[Groups(['revocation:read'])]
    private array $supportsBloques = [];

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['revocation:read'])]
    private \DateTimeImmutable $genereLe;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->genereLe = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getControleur(): ?Controleur
    {
        return $this->controleur;
    }

    public function setControleur(?Controleur $controleur): self
    {
        $this->controleur = $controleur;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;

        return $this;
    }

    /** @return list<string> */
    public function getSupportsBloques(): array
    {
        return $this->supportsBloques;
    }

    /** @param list<string> $supportsBloques */
    public function setSupportsBloques(array $supportsBloques): self
    {
        $this->supportsBloques = $supportsBloques;

        return $this;
    }

    public function getGenereLe(): \DateTimeImmutable
    {
        return $this->genereLe;
    }

    public function setGenereLe(\DateTimeImmutable $genereLe): self
    {
        $this->genereLe = $genereLe;

        return $this;
    }
}
