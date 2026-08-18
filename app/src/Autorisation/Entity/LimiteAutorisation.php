<?php

declare(strict_types=1);

namespace App\Autorisation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Autorisation\Enum\PerimetreAutorisation;
use App\Autorisation\State\LimiteAutorisationProcessor;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Plafond/périmètre gradué d'une opération sensible, par rôle OU par utilisateur, sur un
 * établissement (RG-AUTZ-02). `plafondMontant` nullable = illimité (comparaison inclusive, `≤`).
 * `cumulJournalierMax` réservé, non exploité v1 (RG-AUTZ-11).
 *
 * Garde applicative « cible exclusive role XOR utilisateur » et « au plus une limite par (opération,
 * cible, établissement) » portées par `LimiteAutorisationProcessor` (pas de contrainte SQL native :
 * MariaDB traite NULL comme toujours distinct dans un index unique, §1 du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'atz_limite_autorisation')]
#[ORM\Index(name: 'idx_limite_operation', columns: ['operation_code'])]
#[ORM\Index(name: 'idx_limite_etablissement', columns: ['etablissement_id'])]
#[Assert\Expression(
    '(this.getRole() === null) !== (this.getUtilisateur() === null)',
    message: 'Une limite porte soit un rôle, soit un utilisateur, jamais les deux ni aucun (RG-AUTZ-02).'
)]
#[ApiResource(
    shortName: 'LimiteAutorisation',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'autorisation.lire') or is_granted('PERM', 'autorisation.gerer')"),
        new Get(security: "is_granted('PERM', 'autorisation.lire') or is_granted('PERM', 'autorisation.gerer')"),
        new Post(security: "is_granted('PERM', 'autorisation.gerer')", processor: LimiteAutorisationProcessor::class),
        new Patch(security: "is_granted('PERM', 'autorisation.gerer')", processor: LimiteAutorisationProcessor::class),
        new Delete(security: "is_granted('PERM', 'autorisation.gerer')", processor: LimiteAutorisationProcessor::class),
    ],
    normalizationContext: ['groups' => ['limite:read']],
    denormalizationContext: ['groups' => ['limite:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['operation' => 'exact', 'role' => 'exact', 'utilisateur' => 'exact', 'etablissement' => 'exact'])]
class LimiteAutorisation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['limite:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: OperationSensible::class)]
    #[ORM\JoinColumn(name: 'operation_code', referencedColumnName: 'code', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['limite:read', 'limite:write'])]
    private ?OperationSensible $operation = null;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['limite:read', 'limite:write'])]
    private ?Role $role = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['limite:read', 'limite:write'])]
    private ?Utilisateur $utilisateur = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['limite:read', 'limite:write'])]
    private ?Etablissement $etablissement = null;

    /** `null` = illimité (comparaison inclusive `≤`). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['limite:read', 'limite:write'])]
    private ?string $plafondMontant = null;

    #[ORM\Column(length: 20, enumType: PerimetreAutorisation::class)]
    #[Assert\NotNull]
    #[Groups(['limite:read', 'limite:write'])]
    private PerimetreAutorisation $perimetre = PerimetreAutorisation::PropreEtablissement;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['limite:read', 'limite:write'])]
    private bool $escaladeAuDela = false;

    /** Réservé, non exploité v1 (RG-AUTZ-11). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['limite:read', 'limite:write'])]
    private ?string $cumulJournalierMax = null;

    #[ORM\Column]
    #[Groups(['limite:read'])]
    private \DateTimeImmutable $dateCreation;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['limite:read', 'limite:write'])]
    private ?Utilisateur $auteur = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOperation(): ?OperationSensible
    {
        return $this->operation;
    }

    public function setOperation(?OperationSensible $operation): self
    {
        $this->operation = $operation;

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

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): self
    {
        $this->utilisateur = $utilisateur;

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

    public function getPlafondMontant(): ?string
    {
        return $this->plafondMontant;
    }

    public function setPlafondMontant(?string $plafondMontant): self
    {
        $this->plafondMontant = $plafondMontant;

        return $this;
    }

    public function getPerimetre(): PerimetreAutorisation
    {
        return $this->perimetre;
    }

    public function setPerimetre(PerimetreAutorisation $perimetre): self
    {
        $this->perimetre = $perimetre;

        return $this;
    }

    public function isEscaladeAuDela(): bool
    {
        return $this->escaladeAuDela;
    }

    public function setEscaladeAuDela(bool $escaladeAuDela): self
    {
        $this->escaladeAuDela = $escaladeAuDela;

        return $this;
    }

    public function getCumulJournalierMax(): ?string
    {
        return $this->cumulJournalierMax;
    }

    public function setCumulJournalierMax(?string $cumulJournalierMax): self
    {
        $this->cumulJournalierMax = $cumulJournalierMax;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }
}
