<?php

declare(strict_types=1);

namespace App\Group\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Crm\Entity\Client;
use App\Group\Enum\GroupType;
use App\Group\State\EstablishmentStampProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Groupe de participants (module transverse `App\Group`) : un groupe réutilisable — classe scolaire,
 * comité d'entreprise, tour-opérateur, association — indépendant du métier. Il porte un contact
 * organisateur, un effectif prévisionnel et une liste nominative facultative ; on le RÉSERVE ensuite
 * via `GroupBooking`, sur n'importe quelle activité (piscine, patinoire, musée, accrobranche…).
 *
 * ⚠ NE PAS CONFONDRE avec `App\Organisation\Entity\Groupe`, qui est le LOCATAIRE (tenant) — un
 * exploitant multi-établissements. Ici, un « groupe » est un groupe de personnes reçu par un
 * établissement, et c'est bien pour lever cette ambiguïté que le module et ses classes sont en
 * anglais (D5).
 *
 * D41 — `etablissement` vient de la session serveur (`EstablishmentStampProcessor`), jamais du corps
 * de la requête : hors groupe d'écriture, sans `Assert\NotNull` (la validation s'exécute avant
 * l'estampillage), garanti par la colonne `NOT NULL` et le garde global.
 *
 * @sans-suppression: un groupe est réutilisable d'une visite à l'autre (le « carnet de groupes ») et
 * sert d'ancre à ses réservations et à sa liste ; on l'édite, on ne le supprime pas — ses membres se
 * retirent un à un (DELETE d'un participant), ses réservations s'annulent (POST /cancel).
 */
#[ORM\Entity]
#[ORM\Table(name: 'group_participant_group')]
#[ApiResource(
    shortName: 'ParticipantGroup',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'group.read')"),
        new Get(security: "is_granted('PERM', 'group.read')"),
        new Post(security: "is_granted('PERM', 'group.manage')", processor: EstablishmentStampProcessor::class),
        new Patch(security: "is_granted('PERM', 'group.manage')"),
    ],
    normalizationContext: ['groups' => ['participant_group:read']],
    denormalizationContext: ['groups' => ['participant_group:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['type' => 'exact', 'client' => 'exact', 'label' => 'partial'])]
class ParticipantGroup
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['participant_group:read', 'group_booking:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['participant_group:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Groups(['participant_group:read', 'participant_group:write', 'group_booking:read'])]
    private string $label = '';

    #[ORM\Column(length: 20, enumType: GroupType::class, options: ['default' => 'other'])]
    #[Groups(['participant_group:read', 'participant_group:write'])]
    private GroupType $type = GroupType::Other;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Groups(['participant_group:read', 'participant_group:write'])]
    private string $organizerName = '';

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Groups(['participant_group:read', 'participant_group:write'])]
    private ?string $organizerEmail = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['participant_group:read', 'participant_group:write'])]
    private ?string $organizerPhone = null;

    /**
     * Client B2B facultatif — un comité d'entreprise, une société ou une collectivité qu'on
     * facturera. La résolution d'IRI traverse le périmètre CRM : un client hors périmètre ne se lie
     * pas.
     */
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['participant_group:read', 'participant_group:write'])]
    private ?Client $client = null;

    /**
     * Effectif prévisionnel du groupe. Permet le mode « nombre seul » (le musée n'a longtemps connu
     * que celui-ci) sans exiger la saisie nominative. La liste `participants` le complète, elle ne le
     * remplace pas.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['participant_group:read', 'participant_group:write'])]
    private int $headcount = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['participant_group:read', 'participant_group:write'])]
    private ?string $notes = null;

    /** @var Collection<int, GroupParticipant> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: GroupParticipant::class, cascade: ['remove'], orphanRemoval: true)]
    #[Groups(['participant_group:read'])]
    private Collection $participants;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['participant_group:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->participants = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getType(): GroupType
    {
        return $this->type;
    }

    public function setType(GroupType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getOrganizerName(): string
    {
        return $this->organizerName;
    }

    public function setOrganizerName(string $organizerName): self
    {
        $this->organizerName = $organizerName;

        return $this;
    }

    public function getOrganizerEmail(): ?string
    {
        return $this->organizerEmail;
    }

    public function setOrganizerEmail(?string $organizerEmail): self
    {
        $this->organizerEmail = $organizerEmail;

        return $this;
    }

    public function getOrganizerPhone(): ?string
    {
        return $this->organizerPhone;
    }

    public function setOrganizerPhone(?string $organizerPhone): self
    {
        $this->organizerPhone = $organizerPhone;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getHeadcount(): int
    {
        return $this->headcount;
    }

    public function setHeadcount(int $headcount): self
    {
        $this->headcount = $headcount;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /** @return Collection<int, GroupParticipant> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
