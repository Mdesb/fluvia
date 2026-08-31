<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Crm\Enum\ActivityType;
use App\Crm\State\CrmEstablishmentStampProcessor;
use App\Crm\State\FollowUpProvider;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * UN ÉCHANGE COMMERCIAL — et le geste qu'il appelle ensuite.
 *
 * **Pourquoi ce module est distinct de `Support`, alors que les mécanismes se ressemblent.** Maxime,
 * le 27/08 : *« leurs fonctions ne sont pas les mêmes même si certaines fonctionnalités sont
 * similaires »*. Il a raison, et la différence n'est pas de forme :
 *
 * | | Ticket d'assistance | Activité commerciale |
 * |---|---|---|
 * | Origine | **subie** — quelqu'un demande | **décidée** — on choisit d'appeler |
 * | Fin | quand on a répondu | il n'y en a pas : on rappelle |
 * | Objet | un problème | une relation |
 *
 * Un ticket se **ferme**. Une relation se **poursuit**. Les ranger dans le même objet forcerait un
 * statut « fermé » sur un client qu'on rappellera dans six mois.
 *
 * ---
 *
 * **ET POURTANT IL N'Y A PAS DE SECONDE BOÎTE DE TÂCHES. C'EST LA PIÈCE CENTRALE.**
 *
 * Le risque d'un module commercial à côté de `Support` était d'ouvrir **deux listes de travail en
 * cours** dans le même produit — et personne ne regarde les deux. Il est écarté par la forme même de
 * cette entité :
 *
 * - une activité enregistre **ce qui s'est passé** (`occurredAt`, `summary`) ;
 * - et, facultativement, **ce qu'il faut faire ensuite** (`nextActionAt`, `nextAction`).
 *
 * **On ne coche jamais une relance : on la remplace en agissant.** Le jour où l'on rappelle, on
 * enregistre un nouvel échange — et c'est ce nouvel échange qui porte, ou non, la relance suivante.
 * Une relance est donc « en attente » tant qu'aucune activité plus récente n'existe sur la même cible :
 * un état **déduit**, jamais tenu à la main.
 *
 * > **Une tâche qu'il faut penser à cocher est une tâche qui reste ouverte pour toujours.**
 *
 * ---
 *
 * **UNE ACTIVITÉ EST TOUJOURS ACCROCHÉE À QUELQUE CHOSE.** Client, affaire, ou les deux — jamais
 * flottante. C'est ce qui empêche la question « où est-ce que je note ça ? » : si ça vient de
 * l'extérieur et parle d'un problème, c'est un ticket ; si ça parle d'un client ou d'une affaire,
 * c'est ici.
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_commercial_activity')]
#[ORM\Index(name: 'idx_crm_activity_customer', columns: ['customer_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_crm_activity_followup', columns: ['establishment_id', 'next_action_at'])]
#[ApiResource(
    shortName: 'CommercialActivity',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'crm.lire')"),
        new Get(security: "is_granted('PERM', 'crm.lire')"),
        new Post(
            security: "is_granted('PERM', 'crm.creer') or is_granted('PERM', 'crm.modifier')",
            denormalizationContext: ['groups' => ['activity:write']],
            processor: CrmEstablishmentStampProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'crm.modifier')",
            denormalizationContext: ['groups' => ['activity:write']],
        ),
        // LES RELANCES EN ATTENTE — deduites, jamais stockees.
        new GetCollection(
            uriTemplate: '/crm/relances',
            security: "is_granted('PERM', 'crm.lire')",
            provider: FollowUpProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['activity:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['customer' => 'exact', 'opportunity' => 'exact', 'type' => 'exact'])]
class CommercialActivity
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['activity:read'])]
    private Uuid $id;

    /** Posé par `CrmEstablishmentStampProcessor`, jamais par le corps de la requête (D41). */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['activity:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[Groups(['activity:read', 'activity:write'])]
    private ?Client $customer = null;

    #[ORM\ManyToOne(targetEntity: Opportunity::class)]
    #[Groups(['activity:read', 'activity:write'])]
    private ?Opportunity $opportunity = null;

    #[ORM\Column(length: 20, enumType: ActivityType::class)]
    #[Groups(['activity:read', 'activity:write'])]
    private ActivityType $type = ActivityType::Call;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['activity:read', 'activity:write'])]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Groups(['activity:read', 'activity:write'])]
    private string $summary = '';

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[Groups(['activity:read'])]
    private ?Utilisateur $author = null;

    /**
     * Quand rappeler. `null` quand l'échange se suffit à lui-même.
     *
     * Une **date** et non un horodatage : « rappeler le 12 » est une intention de journée, pas un
     * rendez-vous. Prétendre à l'heure près obligerait à en inventer une.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['activity:read', 'activity:write'])]
    private ?\DateTimeImmutable $nextActionAt = null;

    /** Ce qu'il faut faire. Sans ça, on retrouve une date et plus la raison. */
    #[ORM\Column(length: 250, nullable: true)]
    #[Groups(['activity:read', 'activity:write'])]
    private ?string $nextAction = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->occurredAt = new \DateTimeImmutable();
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

    public function getCustomer(): ?Client
    {
        return $this->customer;
    }

    public function setCustomer(?Client $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getOpportunity(): ?Opportunity
    {
        return $this->opportunity;
    }

    public function setOpportunity(?Opportunity $opportunity): self
    {
        $this->opportunity = $opportunity;

        return $this;
    }

    public function getType(): ActivityType
    {
        return $this->type;
    }

    public function setType(ActivityType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function getAuthor(): ?Utilisateur
    {
        return $this->author;
    }

    public function setAuthor(?Utilisateur $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getNextActionAt(): ?\DateTimeImmutable
    {
        return $this->nextActionAt;
    }

    public function setNextActionAt(?\DateTimeImmutable $nextActionAt): self
    {
        $this->nextActionAt = $nextActionAt;

        return $this;
    }

    public function getNextAction(): ?string
    {
        return $this->nextAction;
    }

    public function setNextAction(?string $nextAction): self
    {
        $this->nextAction = $nextAction;

        return $this;
    }

    /**
     * Une activité accrochée à rien serait introuvable.
     *
     * Elle n'apparaîtrait ni sur une fiche client ni sur une affaire — donc nulle part, et elle aurait
     * pourtant l'air enregistrée. C'est le genre de saisie qu'on refait trois fois avant de comprendre.
     */
    #[Assert\Callback]
    public function validerRattachement(ExecutionContextInterface $context): void
    {
        if ($this->customer === null && $this->opportunity === null) {
            $context->buildViolation('Une activité doit être rattachée à un client ou à une affaire : sinon elle n’apparaît nulle part.')
                ->atPath('customer')
                ->addViolation();
        }
    }

    /**
     * Une date de relance sans intitulé se retrouve sans sa raison.
     *
     * Six semaines plus tard, « rappeler le 12 » ne dit ni pourquoi ni de quoi parler — et l'appel ne
     * se fait pas.
     */
    #[Assert\Callback]
    public function validerRelance(ExecutionContextInterface $context): void
    {
        if ($this->nextActionAt !== null && ($this->nextAction === null || trim($this->nextAction) === '')) {
            $context->buildViolation('Indiquez ce qu’il faudra faire : une date seule ne dit pas de quoi parler.')
                ->atPath('nextAction')
                ->addViolation();
        }
    }
}
