<?php

declare(strict_types=1);

namespace App\Project\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Project\Enum\ProjectStatus;
use App\Project\State\ProjectBoardProvider;
use App\Project\State\ProjectEstablishmentStampProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UN PROJET — un travail qui a une fin, un responsable et des tâches.
 *
 * **Ce que ce module n'est pas.** Ce n'est pas `Support` : un ticket arrive de l'extérieur et se ferme
 * quand on a répondu. Ce n'est pas `Platform\ScheduledTask` : celle-là est une tâche *machine*,
 * planifiée en cron. Un projet est un travail *humain*, décidé en interne, avec une échéance — refaire
 * les vestiaires, ouvrir la patinoire éphémère, préparer la saison.
 *
 * La distinction mérite d'être écrite, parce qu'elle se perd vite : le jour où quelqu'un range une
 * relance commerciale ici plutôt que dans un ticket, **il existe deux endroits où chercher du travail
 * en cours**, et plus personne ne regarde les deux.
 *
 * ---
 *
 * **L'AVANCEMENT NE SE STOCKE PAS.**
 *
 * Il se compte depuis les tâches. Une colonne « avancement » demanderait d'être recalculée à chaque
 * modification de tâche — donc partout, donc oubliée quelque part. Un pourcentage faux est pire
 * qu'absent : il rassure.
 *
 * > **Un chiffre qu'on peut compter ne se recopie pas.** Même règle que l'étape d'une affaire, lue du
 * > devis plutôt que dupliquée.
 *
 * **Et « en retard » n'est pas un état** : c'est une échéance comparée à aujourd'hui. En faire un
 * statut obligerait à le maintenir toutes les nuits, et un projet serait à l'heure jusqu'au prochain
 * passage, puis en retard d'un coup, sans que rien ne se soit produit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_project')]
#[ORM\Index(name: 'idx_project_establishment_status', columns: ['establishment_id', 'status'])]
#[ApiResource(
    shortName: 'Project',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'personnel.lire') or is_granted('PERM', 'organisation.gerer')"),
        new Get(security: "is_granted('PERM', 'personnel.lire') or is_granted('PERM', 'organisation.gerer')"),
        new Post(
            security: "is_granted('PERM', 'personnel.gerer') or is_granted('PERM', 'organisation.gerer')",
            denormalizationContext: ['groups' => ['project:write']],
            processor: ProjectEstablishmentStampProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'personnel.gerer') or is_granted('PERM', 'organisation.gerer')",
            denormalizationContext: ['groups' => ['project:write']],
        ),
        // Le tableau : projets ouverts, avancement COMPTE et retard DEDUIT.
        new GetCollection(
            uriTemplate: '/projets/tableau',
            security: "is_granted('PERM', 'personnel.lire') or is_granted('PERM', 'organisation.gerer')",
            provider: ProjectBoardProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['project:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact'])]
class Project
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['project:read', 'project_task:read'])]
    private Uuid $id;

    /** Posé par `ProjectEstablishmentStampProcessor`, jamais par le corps de la requête (D41). */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['project:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Groups(['project:read', 'project:write', 'project_task:read'])]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: ProjectStatus::class)]
    #[Groups(['project:read', 'project:write'])]
    private ProjectStatus $status = ProjectStatus::Planned;

    /**
     * Le responsable. Nullable, et il vaut mieux qu'il puisse le rester.
     *
     * Un projet sans responsable est un problème **visible** ; exiger le champ ferait désigner
     * quelqu'un au hasard à la création — le même problème, rendu invisible.
     */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[Groups(['project:read', 'project:write'])]
    private ?Utilisateur $owner = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?\DateTimeImmutable $dueDate = null;

    /** @var Collection<int, ProjectTask> */
    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ProjectTask::class)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['project:read'])]
    private Collection $tasks;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['project:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->tasks = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getStatus(): ProjectStatus
    {
        return $this->status;
    }

    public function setStatus(ProjectStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getOwner(): ?Utilisateur
    {
        return $this->owner;
    }

    public function setOwner(?Utilisateur $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

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

    /** @return Collection<int, ProjectTask> */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
