<?php

declare(strict_types=1);

namespace App\Securite\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Securite\State\AffectationProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Affectation : lie un Utilisateur à un Rôle POUR un Établissement donné (RG-SOCLE-03).
 * Le triplet (utilisateur, role, etablissement) est unique. Porte la dimension multi-entités.
 *
 * RG-M8-06/09 (US-L7-03/07) : la création est gardée par `AffectationProcessor` (garde MFA sur
 * rôle à privilèges, plafond d'attribution). RG-M8-07 : la suppression est gardée par
 * `AffectationProcessor` (garde dernier administrateur).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sec_affectation')]
#[ORM\UniqueConstraint(name: 'uniq_affectation', columns: ['utilisateur_id', 'role_id', 'etablissement_id'])]
#[ApiResource(
    shortName: 'Affectation',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'securite.gerer')"),
        new Get(security: "is_granted('PERM', 'securite.gerer')"),
        new Post(security: "is_granted('PERM', 'securite.gerer')", processor: AffectationProcessor::class),
        new Delete(security: "is_granted('PERM', 'securite.gerer')", processor: AffectationProcessor::class),
    ],
    normalizationContext: ['groups' => ['affectation:read']],
    denormalizationContext: ['groups' => ['affectation:write']],
)]
class Affectation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['affectation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['affectation:read', 'affectation:write'])]
    private ?Utilisateur $utilisateur = null;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['affectation:read', 'affectation:write'])]
    private ?Role $role = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['affectation:read', 'affectation:write'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): self
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getRole(): ?Role
    {
        return $this->role;
    }

    public function setRole(?Role $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }
}
