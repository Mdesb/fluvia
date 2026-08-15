<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\Support;
use App\Acces\Enum\StatutSupport;
use App\Piscine\State\BraceletEtancheProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Bracelet RFID étanche (RG-PISC-04, US-L6-10) : spécialisation d'un `Support` L3 (`type = RFID`,
 * validé par `BraceletEtancheProcessor`). Aucun champ `etat` dupliqué — lu via `support.getStatut()`
 * (actif/bloqué). `beneficiaireRef` est une référence logique (M4/CRM non codé à date, plan §0).
 */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_bracelet_etanche')]
#[ORM\UniqueConstraint(name: 'uniq_bracelet_support', columns: ['support_id'])]
#[ApiResource(
    shortName: 'BraceletEtanche',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
        new Post(security: "is_granted('PERM', 'acces.appairer')", processor: BraceletEtancheProcessor::class),
        new Patch(security: "is_granted('PERM', 'acces.appairer')"),
    ],
    normalizationContext: ['groups' => ['bracelet:read']],
    denormalizationContext: ['groups' => ['bracelet:write']],
)]
class BraceletEtanche
{
    /** @var list<string> Rôles reconnus (cahier §2). */
    public const ROLE_ACCES = 'acces';
    public const ROLE_CASIER = 'casier';
    public const ROLE_DOUCHE = 'douche';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['bracelet:read', 'casier:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Support::class)]
    #[ORM\JoinColumn(name: 'support_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['bracelet:read', 'bracelet:write', 'casier:read'])]
    private ?Support $support = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['bracelet:read', 'bracelet:write'])]
    private ?Uuid $beneficiaireRef = null;

    /** @var list<string> Sous-ensemble non vide de {acces,casier,douche}. */
    #[ORM\Column(type: 'simple_array')]
    #[Assert\Count(min: 1, minMessage: 'Au moins un rôle est requis (cahier §2).')]
    #[Groups(['bracelet:read', 'bracelet:write'])]
    private array $roles = [self::ROLE_ACCES];

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSupport(): ?Support
    {
        return $this->support;
    }

    public function setSupport(?Support $support): self
    {
        $this->support = $support;

        return $this;
    }

    public function getBeneficiaireRef(): ?Uuid
    {
        return $this->beneficiaireRef;
    }

    public function setBeneficiaireRef(?Uuid $beneficiaireRef): self
    {
        $this->beneficiaireRef = $beneficiaireRef;

        return $this;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): self
    {
        $this->roles = array_values($roles);

        return $this;
    }

    public function aRole(string $role): bool
    {
        return \in_array($role, $this->roles, true);
    }

    /** Délégation — statut porté par le `Support` L3 (actif/bloqué), pas de duplication. */
    #[Groups(['bracelet:read'])]
    public function getEtat(): ?StatutSupport
    {
        return $this->support?->getStatut();
    }
}
