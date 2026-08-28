<?php

declare(strict_types=1);

namespace App\Securite\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use App\Securite\State\ApercuDroitsRoleProvider;
use App\Securite\State\DuplicationRoleProcessor;
use App\Securite\State\RoleProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rôle : agrège des permissions (RG-SOCLE-03). Le nom est unique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sec_role')]
#[ORM\UniqueConstraint(name: 'uniq_role_nom', columns: ['nom'])]
#[ApiFilter(BooleanFilter::class, properties: ['estModele'])]
#[ApiResource(
    shortName: 'Role',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'securite.gerer')"),
        new Get(security: "is_granted('PERM', 'securite.gerer')"),
        new Post(security: "is_granted('PERM', 'securite.gerer')"),
        new Patch(security: "is_granted('PERM', 'securite.gerer')", processor: RoleProcessor::class),
        new Delete(security: "is_granted('PERM', 'securite.gerer')", processor: RoleProcessor::class),
        // Duplication (US-L7-04, CA-12) : copie indépendante du rôle et de ses permissions.
        new Post(
            uriTemplate: '/roles/{id}/dupliquer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'securite.gerer')",
            processor: DuplicationRoleProcessor::class,
        ),
        // Aperçu des droits d'un rôle sur un établissement (US-L7-05, CA-13) : lecture seule.
        new Get(
            uriTemplate: '/roles/{id}/apercu-droits',
            security: "is_granted('PERM', 'securite.gerer')",
            provider: ApercuDroitsRoleProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['role:read']],
    denormalizationContext: ['groups' => ['role:write']],
)]
class Role
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['role:read', 'affectation:read', 'me:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['role:read', 'role:write', 'affectation:read', 'me:read', 'limite:read'])]
    private string $nom = '';

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class)]
    #[ORM\JoinTable(name: 'sec_role_permission')]
    #[Groups(['role:read', 'role:write', 'me:read'])]
    private Collection $permissions;

    /** Rôle-modèle réutilisable (cahier M8-02, US-L7-04) : point de départ, pas nécessairement affecté. */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['role:read', 'role:write'])]
    private bool $estModele = false;

    /** Traçabilité de duplication (§2.8 plan) : orphelin proprement si l'origine est supprimée. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'role_modele_origine_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['role:read'])]
    private ?self $roleModeleOrigine = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->permissions = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    /** @return Collection<int, Permission> */
    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    public function addPermission(Permission $permission): self
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
        }

        return $this;
    }

    public function removePermission(Permission $permission): self
    {
        $this->permissions->removeElement($permission);

        return $this;
    }

    public function isEstModele(): bool
    {
        return $this->estModele;
    }

    public function setEstModele(bool $estModele): self
    {
        $this->estModele = $estModele;

        return $this;
    }

    public function getRoleModeleOrigine(): ?self
    {
        return $this->roleModeleOrigine;
    }

    public function setRoleModeleOrigine(?self $roleModeleOrigine): self
    {
        $this->roleModeleOrigine = $roleModeleOrigine;

        return $this;
    }
}
