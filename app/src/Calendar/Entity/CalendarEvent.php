<?php

declare(strict_types=1);

namespace App\Calendar\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Calendar\Enum\CalendarEventType;
use App\Calendar\State\CalendarEventProcessor;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CE QU'ON NOTE À LA MAIN DANS L'AGENDA : une réunion, une intervention, une indisponibilité.
 *
 * ── LE PROPRIÉTAIRE EST CE QUI SÉPARE « LE SITE » DE « MOI » ────────────────────────────────────
 *
 * `proprietaire = null` : l'événement appartient au SITE et tout le monde le voit. `proprietaire`
 * renseigné : il n'appartient qu'à cette personne, et personne d'autre ne le lit — pas même un
 * administrateur, parce qu'un blocage personnel dans un agenda professionnel dit parfois autre
 * chose qu'un horaire.
 *
 * Maxime a demandé les deux onglets le 28/08. Ils ne sont pas deux écrans ni deux entités : c'est
 * la MÊME table, lue par un filtre. Deux tables auraient dupliqué la saisie, l'export ICS et le
 * cloisonnement — trois occasions de diverger pour une seule différence, qui tient dans une colonne.
 *
 * ── QUI PEUT ÉCRIRE QUOI ────────────────────────────────────────────────────────────────────────
 *
 * Tout compte authentifié écrit SES événements ; poser un événement DU SITE demande
 * `organisation.gerer` ou `personnel.gerer`. La règle est appliquée par `CalendarEventProcessor`
 * et pas par l'attribut `security` : elle dépend de ce que la charge utile contient, pas seulement
 * de qui appelle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'calendar_event')]
#[ORM\Index(name: 'idx_calendar_event_establishment_start', columns: ['establishment_id', 'starts_at'])]
#[ApiResource(
    shortName: 'CalendarEvent',
    operations: [
        // Trente par defaut : un mois charge en depasse. La vue d'agenda passe par
        // `/calendar/feed`, qui n'est pas pagine ; cette collection sert la relecture et la
        // suppression, et elle doit rendre ce qu'elle annonce.
        new GetCollection(
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            paginationItemsPerPage: 200,
        ),
        new Get(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Post(security: "is_granted('IS_AUTHENTICATED_FULLY')", processor: CalendarEventProcessor::class),
        new Patch(security: "is_granted('IS_AUTHENTICATED_FULLY')", processor: CalendarEventProcessor::class),
        new Delete(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
    ],
    routePrefix: '/calendar',
    normalizationContext: ['groups' => ['calendar_event:read']],
    denormalizationContext: ['groups' => ['calendar_event:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['type' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['start', 'end'])]
class CalendarEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['calendar_event:read'])]
    private Uuid $id;

    /** Posé par le processor depuis l'établissement actif — jamais lu du corps de requête. */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['calendar_event:read'])]
    private ?Etablissement $establishment = null;

    /**
     * `null` = événement du site. Posé par le processor : accepter un propriétaire du corps
     * laisserait écrire dans l'agenda personnel de quelqu'un d'autre.
     */
    // `onDelete: CASCADE` DÉCLARÉ AU MAPPING, et pas seulement écrit dans la migration : sans lui,
    // Doctrine relit la contrainte comme une dérive et propose de la retirer — c'est-à-dire de
    // laisser les événements personnels d'un compte supprimé traîner sans propriétaire.
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['calendar_event:read'])]
    private ?Utilisateur $owner = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Un événement sans titre est une case colorée dont personne ne sait ce qu’elle dit.')]
    #[Groups(['calendar_event:read', 'calendar_event:write'])]
    private string $title = '';

    // `name:` explicite : `START` et `END` sont des mots reserves SQL. Une colonne qu'il faut
    // echapper est une colonne qu'on oubliera d'echapper — dans une requete brute, un jour.
    #[ORM\Column(name: 'starts_at', type: 'datetime_immutable')]
    #[Assert\NotNull]
    #[Groups(['calendar_event:read', 'calendar_event:write'])]
    private ?\DateTimeImmutable $start = null;

    #[ORM\Column(name: 'ends_at', type: 'datetime_immutable')]
    #[Assert\NotNull]
    #[Groups(['calendar_event:read', 'calendar_event:write'])]
    private ?\DateTimeImmutable $end = null;

    #[ORM\Column]
    #[Groups(['calendar_event:read', 'calendar_event:write'])]
    private bool $allDay = false;

    #[ORM\Column(length: 24, enumType: CalendarEventType::class)]
    #[Groups(['calendar_event:read', 'calendar_event:write'])]
    private CalendarEventType $type = CalendarEventType::Other;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['calendar_event:read', 'calendar_event:write'])]
    private ?string $notes = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getOwner(): ?Utilisateur
    {
        return $this->owner;
    }

    public function setOwner(?Utilisateur $owner): self
    {
        $this->owner = $owner;

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

    public function getStart(): ?\DateTimeImmutable
    {
        return $this->start;
    }

    public function setStart(?\DateTimeImmutable $debut): self
    {
        $this->start = $debut;

        return $this;
    }

    public function getEnd(): ?\DateTimeImmutable
    {
        return $this->end;
    }

    public function setEnd(?\DateTimeImmutable $fin): self
    {
        $this->end = $fin;

        return $this;
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function setAllDay(bool $allDay): self
    {
        $this->allDay = $allDay;

        return $this;
    }

    public function getType(): CalendarEventType
    {
        return $this->type;
    }

    public function setType(CalendarEventType $type): self
    {
        $this->type = $type;

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

    /** Vrai si l'événement appartient au site et non à une personne. */
    #[Groups(['calendar_event:read'])]
    public function isSiteWide(): bool
    {
        return $this->owner === null;
    }
}
