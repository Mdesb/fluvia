<?php

declare(strict_types=1);

namespace App\Project\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Project\Enum\TaskStatus;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UNE TÂCHE D'UN PROJET.
 *
 * **Pas d'établissement propre, et c'est le point.** Une tâche tient le sien de son projet — patron
 * des entités satellites du dépôt (`Beneficiaire` → `client`, `GrilleTarifaire` → `produit`), et
 * `ProjectScopeExtension` la nomme avec son chemin de jointure. Lui donner une colonne ouvrirait
 * la possibilité qu'une tâche appartienne à un autre établissement que son projet — une incohérence
 * que rien ne rattraperait.
 *
 * **`doneAt` est posé par le mutateur de statut, jamais par l'appelant.** Une date de réalisation
 * fournie de l'extérieur peut mentir, et surtout elle peut manquer : on aurait des tâches faites sans
 * date, invisibles de tout décompte par période.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_task')]
#[ORM\Index(name: 'idx_project_task_project', columns: ['project_id', 'status'])]
#[ApiResource(
    shortName: 'ProjectTask',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'personnel.lire') or is_granted('PERM', 'organisation.gerer')"),
        new Get(security: "is_granted('PERM', 'personnel.lire') or is_granted('PERM', 'organisation.gerer')"),
        new Post(
            security: "is_granted('PERM', 'personnel.gerer') or is_granted('PERM', 'organisation.gerer')",
            denormalizationContext: ['groups' => ['project_task:write']],
        ),
        new Patch(
            security: "is_granted('PERM', 'personnel.gerer') or is_granted('PERM', 'organisation.gerer')",
            denormalizationContext: ['groups' => ['project_task:write']],
        ),
        new Delete(security: "is_granted('PERM', 'personnel.gerer') or is_granted('PERM', 'organisation.gerer')"),
    ],
    normalizationContext: ['groups' => ['project_task:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['project' => 'exact', 'status' => 'exact'])]
class ProjectTask
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['project_task:read', 'project:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['project_task:read', 'project_task:write'])]
    private ?Project $project = null;

    #[ORM\Column(length: 250)]
    #[Assert\NotBlank]
    #[Groups(['project_task:read', 'project_task:write', 'project:read'])]
    private string $title = '';

    #[ORM\Column(length: 20, enumType: TaskStatus::class)]
    #[Groups(['project_task:read', 'project_task:write', 'project:read'])]
    private TaskStatus $status = TaskStatus::Todo;

    /**
     * À qui elle est confiée.
     *
     * Nullable : une tâche sans responsable existe, et l'écran doit la **montrer** plutôt que de la
     * refuser. Une tâche que personne n'a prise est précisément celle qu'il faut voir.
     */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[Groups(['project_task:read', 'project_task:write', 'project:read'])]
    private ?Utilisateur $assignee = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['project_task:read', 'project_task:write', 'project:read'])]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['project_task:read', 'project_task:write', 'project:read'])]
    private int $position = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['project_task:read', 'project:read'])]
    private ?\DateTimeImmutable $doneAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    /**
     * Le passage à « faite » horodate, le retour en arrière efface.
     *
     * Sans l'effacement, une tâche rouverte garderait sa date de réalisation — et compterait comme
     * faite dans tout décompte par période, alors qu'elle est de nouveau ouverte à l'écran. Deux
     * chiffres contradictoires, dont un seul se voit.
     */
    public function setStatus(TaskStatus $status): self
    {
        $this->status = $status;
        $this->doneAt = $status === TaskStatus::Done ? ($this->doneAt ?? new \DateTimeImmutable()) : null;

        return $this;
    }

    public function getAssignee(): ?Utilisateur
    {
        return $this->assignee;
    }

    public function setAssignee(?Utilisateur $assignee): self
    {
        $this->assignee = $assignee;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): self
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getDoneAt(): ?\DateTimeImmutable
    {
        return $this->doneAt;
    }
}
