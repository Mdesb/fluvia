<?php

declare(strict_types=1);

namespace App\Securite\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Securite\Enum\StatutDelegation;
use App\Securite\State\DelegationDroitProcessor;
use App\Securite\State\RevocationDelegationProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Délégation temporaire de droits (US-L7-07, décision actée) : `dateFin` obligatoire, révocation
 * automatique à échéance (commande `securite:delegations:expirer`) ou anticipée (endpoint dédié).
 * Le sous-ensemble de droits délégué est celui d'un `Role` entier (⚠ HYPOTHÈSE §1.4 plan, pas de
 * sélection fine de permissions en MVP).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sec_delegation_droit')]
#[ORM\Index(name: 'idx_delegation_beneficiaire_etab_statut', columns: ['beneficiaire_id', 'etablissement_id', 'statut'])]
#[ORM\Index(name: 'idx_delegation_statut_date_fin', columns: ['statut', 'date_fin'])]
#[ApiFilter(SearchFilter::class, properties: ['beneficiaire' => 'exact', 'etablissement' => 'exact', 'statut' => 'exact'])]
#[ApiResource(
    shortName: 'DelegationDroit',
    operations: [
        new GetCollection(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Get(security: "is_granted('PERM', 'securite.gerer') or is_granted('PERM', 'securite.lire') or object.getBeneficiaire() == user or object.getDelegant() == user"),
        new Post(
            security: "is_granted('PERM', 'securite.gerer')",
            processor: DelegationDroitProcessor::class,
        ),
        new Post(
            uriTemplate: '/delegations/{id}/revoquer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'securite.gerer') or object.getDelegant() == user",
            processor: RevocationDelegationProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['delegation:read']],
    denormalizationContext: ['groups' => ['delegation:write']],
)]
class DelegationDroit
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['delegation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['delegation:read', 'delegation:write'])]
    private ?Utilisateur $delegant = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['delegation:read', 'delegation:write'])]
    private ?Utilisateur $beneficiaire = null;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['delegation:read', 'delegation:write'])]
    private ?Role $role = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['delegation:read', 'delegation:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['delegation:read', 'delegation:write'])]
    private ?\DateTimeImmutable $dateDebut = null;

    /** Obligatoire (décision actée, CA-7) : toute délégation sans date de fin est refusée (422). */
    #[ORM\Column(nullable: false)]
    #[Assert\NotNull(message: 'La date de fin est obligatoire pour une délégation de droits.')]
    #[Assert\Expression(
        'this.getDateDebut() === null or this.getDateFin() === null or this.getDateFin() > this.getDateDebut()',
        message: 'La date de fin doit être postérieure à la date de début.'
    )]
    #[Groups(['delegation:read', 'delegation:write'])]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(length: 12, enumType: StatutDelegation::class)]
    #[Groups(['delegation:read'])]
    private StatutDelegation $statut = StatutDelegation::Active;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['delegation:read'])]
    private ?string $motifRevocation = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['delegation:read'])]
    private ?Utilisateur $revoquePar = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['delegation:read'])]
    private ?\DateTimeImmutable $dateRevocation = null;

    #[ORM\Column]
    #[Groups(['delegation:read'])]
    private \DateTimeImmutable $dateCreation;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
        $this->dateDebut = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDelegant(): ?Utilisateur
    {
        return $this->delegant;
    }

    public function setDelegant(?Utilisateur $delegant): self
    {
        $this->delegant = $delegant;

        return $this;
    }

    public function getBeneficiaire(): ?Utilisateur
    {
        return $this->beneficiaire;
    }

    public function setBeneficiaire(?Utilisateur $beneficiaire): self
    {
        $this->beneficiaire = $beneficiaire;

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

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): self
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): self
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getStatut(): StatutDelegation
    {
        return $this->statut;
    }

    public function setStatut(StatutDelegation $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getMotifRevocation(): ?string
    {
        return $this->motifRevocation;
    }

    public function setMotifRevocation(?string $motifRevocation): self
    {
        $this->motifRevocation = $motifRevocation;

        return $this;
    }

    public function getRevoquePar(): ?Utilisateur
    {
        return $this->revoquePar;
    }

    public function setRevoquePar(?Utilisateur $revoquePar): self
    {
        $this->revoquePar = $revoquePar;

        return $this;
    }

    public function getDateRevocation(): ?\DateTimeImmutable
    {
        return $this->dateRevocation;
    }

    public function setDateRevocation(?\DateTimeImmutable $dateRevocation): self
    {
        $this->dateRevocation = $dateRevocation;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    /** Active, non expirée à l'instant présent (double garde, cf. commande d'expiration). */
    public function estActiveMaintenant(\DateTimeImmutable $maintenant): bool
    {
        return $this->statut === StatutDelegation::Active
            && $this->dateDebut !== null && $this->dateDebut <= $maintenant
            && $this->dateFin !== null && $this->dateFin >= $maintenant;
    }
}
