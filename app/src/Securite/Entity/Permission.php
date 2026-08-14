<?php

declare(strict_types=1);

namespace App\Securite\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Permission = couple module × action (RG-SOCLE-02). Le couple est unique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sec_permission')]
#[ORM\UniqueConstraint(name: 'uniq_permission_module_action', columns: ['module', 'action'])]
#[ApiResource(
    shortName: 'Permission',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'securite.gerer')"),
        new Get(security: "is_granted('PERM', 'securite.gerer')"),
        new Post(security: "is_granted('PERM', 'securite.gerer')"),
        new Patch(security: "is_granted('PERM', 'securite.gerer')"),
        new Delete(security: "is_granted('PERM', 'securite.gerer')"),
    ],
    normalizationContext: ['groups' => ['permission:read']],
    denormalizationContext: ['groups' => ['permission:write']],
)]
class Permission
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['permission:read', 'role:read', 'me:read'])]
    private Uuid $id;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Groups(['permission:read', 'permission:write', 'role:read', 'me:read'])]
    private string $module = '';

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Groups(['permission:read', 'permission:write', 'role:read', 'me:read'])]
    private string $action = '';

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function setModule(string $module): self
    {
        $this->module = $module;

        return $this;
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

    /** Représentation canonique "module.action" utilisée par le PermissionVoter. */
    #[Groups(['me:read'])]
    public function getCode(): string
    {
        return $this->module . '.' . $this->action;
    }
}
