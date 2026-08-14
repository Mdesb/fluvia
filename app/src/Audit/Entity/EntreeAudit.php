<?php

declare(strict_types=1);

namespace App\Audit\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Journal d'audit des actions sensibles (RG-SOCLE-07). Append-only :
 * l'API n'expose QUE des opérations de lecture (pas de POST/PATCH/DELETE) — CA-6.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_entree')]
#[ORM\Index(name: 'idx_audit_date', columns: ['date_heure'])]
#[ApiResource(
    shortName: 'EntreeAudit',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'securite.gerer')"),
        new Get(security: "is_granted('PERM', 'securite.gerer')"),
    ],
    normalizationContext: ['groups' => ['audit:read']],
)]
class EntreeAudit
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['audit:read'])]
    private Uuid $id;

    /** Email de l'auteur de l'action (null si action système / non authentifiée). */
    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['audit:read'])]
    private ?string $auteur = null;

    #[ORM\Column]
    #[Groups(['audit:read'])]
    private \DateTimeImmutable $dateHeure;

    #[ORM\Column(length: 120)]
    #[Groups(['audit:read'])]
    private string $action = '';

    #[ORM\Column(length: 180)]
    #[Groups(['audit:read'])]
    private string $cibleType = '';

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['audit:read'])]
    private ?string $cibleId = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['audit:read'])]
    private ?Uuid $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateHeure = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getDateHeure(): \DateTimeImmutable
    {
        return $this->dateHeure;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function getCibleType(): string
    {
        return $this->cibleType;
    }

    public function setCibleType(string $cibleType): self
    {
        $this->cibleType = $cibleType;

        return $this;
    }

    public function getCibleId(): ?string
    {
        return $this->cibleId;
    }

    public function setCibleId(?string $cibleId): self
    {
        $this->cibleId = $cibleId;

        return $this;
    }

    public function getEtablissement(): ?Uuid
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Uuid $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }
}
