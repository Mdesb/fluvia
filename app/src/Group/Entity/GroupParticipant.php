<?php

declare(strict_types=1);

namespace App\Group\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Crm\Entity\Beneficiaire;
use App\Group\Enum\ParticipantCategory;
use App\Group\State\CreateGroupParticipantProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Membre nominatif d'un `ParticipantGroup`. La liste est facultative : un groupe peut ne connaître que
 * son effectif. Un membre peut, ou non, pointer vers un `Beneficiaire` CRM déjà connu.
 *
 * Cloisonnement : cette entité n'a **pas** de colonne `etablissement` — elle est rattachée par son
 * `group`, dont le périmètre fait foi (`GroupScopeExtension` la filtre par une jointure). La création
 * est estampillée par `CreateGroupParticipantProcessor`, qui **vérifie** que le groupe visé appartient
 * à l'établissement actif : `find()` ne passe pas par l'extension de périmètre, donc la garde est
 * explicite (RG-SOCLE-05).
 */
#[ORM\Entity]
#[ORM\Table(name: 'group_participant')]
#[ApiResource(
    shortName: 'GroupParticipant',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'group.read')"),
        new Get(security: "is_granted('PERM', 'group.read')"),
        new Post(security: "is_granted('PERM', 'group.manage')", processor: CreateGroupParticipantProcessor::class),
        new Patch(security: "is_granted('PERM', 'group.manage')"),
        new Delete(security: "is_granted('PERM', 'group.manage')"),
    ],
    normalizationContext: ['groups' => ['group_participant:read']],
    denormalizationContext: ['groups' => ['group_participant:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['group' => 'exact', 'category' => 'exact'])]
class GroupParticipant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['group_participant:read', 'participant_group:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ParticipantGroup::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['group_participant:read', 'group_participant:write'])]
    private ?ParticipantGroup $group = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Groups(['group_participant:read', 'group_participant:write', 'participant_group:read'])]
    private string $firstName = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Groups(['group_participant:read', 'group_participant:write', 'participant_group:read'])]
    private string $lastName = '';

    #[ORM\Column(length: 12, enumType: ParticipantCategory::class, options: ['default' => 'adult'])]
    #[Groups(['group_participant:read', 'group_participant:write', 'participant_group:read'])]
    private ParticipantCategory $category = ParticipantCategory::Adult;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['group_participant:read', 'group_participant:write'])]
    private ?Beneficiaire $beneficiaire = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGroup(): ?ParticipantGroup
    {
        return $this->group;
    }

    public function setGroup(?ParticipantGroup $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getCategory(): ParticipantCategory
    {
        return $this->category;
    }

    public function setCategory(ParticipantCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getBeneficiaire(): ?Beneficiaire
    {
        return $this->beneficiaire;
    }

    public function setBeneficiaire(?Beneficiaire $beneficiaire): self
    {
        $this->beneficiaire = $beneficiaire;

        return $this;
    }
}
