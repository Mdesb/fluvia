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
use App\Group\Enum\GroupBookingStatus;
use App\Group\Enum\GroupPaymentStatus;
use App\Group\State\AssignGroupBookingProcessor;
use App\Group\State\CancelGroupBookingProcessor;
use App\Group\State\ConfirmGroupBookingProcessor;
use App\Group\State\CreateGroupBookingProcessor;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Organisation\Entity\Etablissement;
use App\Vente\Entity\Vente;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réservation de groupe (module transverse `App\Group`) : le fait qu'un `ParticipantGroup` soit reçu
 * sur une activité — n'importe laquelle. C'est la **généralisation** du `DossierGroupeScolaire` du
 * musée hors de son vertical : elle vise un `Creneau` (et/ou une `Activite`) de `App\Reservation`, le
 * socle commun des métiers. Une activité nouvelle (accrobranche) est un `Activite` de plus : rien à
 * changer ici.
 *
 * Paiement le plus souvent **différé** (bon de commande, mandat, tiers-payeur) : d'où `paymentStatus`
 * et un `payer` distinct de l'organisateur.
 *
 * D41 — `etablissement` estampillé par le serveur (`CreateGroupBookingProcessor`), jamais dans le
 * corps. Les transitions d'état (`assign`, `confirm`, `cancel`) et l'affectation d'un créneau passent
 * par des opérations dédiées, pas par `Patch` : le contrôle de périmètre des références (le groupe et
 * le créneau doivent appartenir à l'établissement actif) y est fait explicitement, `find()` ne passant
 * pas par l'extension de périmètre.
 *
 * @sans-suppression: une réservation ne se supprime pas, elle s'ANNULE
 * (POST /group/bookings/{id}/cancel) — `Cancelled` conserve la trace de l'engagement et de son abandon,
 * qu'une suppression effacerait.
 */
#[ORM\Entity]
#[ORM\Table(name: 'group_booking')]
#[ApiResource(
    shortName: 'GroupBooking',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'group.read')"),
        new Get(security: "is_granted('PERM', 'group.read')"),
        new Post(
            uriTemplate: '/group/bookings',
            security: "is_granted('PERM', 'group.manage')",
            processor: CreateGroupBookingProcessor::class,
        ),
        new Patch(security: "is_granted('PERM', 'group.manage')"),
        new Post(
            uriTemplate: '/group/bookings/{id}/assign',
            read: true,
            input: false,
            security: "is_granted('PERM', 'group.manage')",
            processor: AssignGroupBookingProcessor::class,
        ),
        new Post(
            uriTemplate: '/group/bookings/{id}/confirm',
            read: true,
            input: false,
            security: "is_granted('PERM', 'group.manage')",
            processor: ConfirmGroupBookingProcessor::class,
        ),
        new Post(
            uriTemplate: '/group/bookings/{id}/cancel',
            read: true,
            input: false,
            security: "is_granted('PERM', 'group.manage')",
            processor: CancelGroupBookingProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['group_booking:read']],
    denormalizationContext: ['groups' => ['group_booking:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['group' => 'exact', 'creneau' => 'exact', 'activite' => 'exact', 'status' => 'exact', 'paymentStatus' => 'exact'])]
class GroupBooking
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['group_booking:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['group_booking:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: ParticipantGroup::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['group_booking:read'])]
    private ?ParticipantGroup $group = null;

    /** Créneau visé (occurrence datée). Facultatif tant que la réservation reste une option à placer. */
    #[ORM\ManyToOne(targetEntity: Creneau::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['group_booking:read'])]
    private ?Creneau $creneau = null;

    /** Activité visée (catalogue), utile quand le créneau n'est pas encore fixé. */
    #[ORM\ManyToOne(targetEntity: Activite::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['group_booking:read'])]
    private ?Activite $activite = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['group_booking:read', 'group_booking:write'])]
    private int $effectif = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['group_booking:read', 'group_booking:write'])]
    private int $accompagnateurs = 0;

    #[ORM\Column(length: 12, enumType: GroupBookingStatus::class, options: ['default' => 'option'])]
    #[Groups(['group_booking:read'])]
    private GroupBookingStatus $status = GroupBookingStatus::Option;

    #[ORM\Column(length: 16, enumType: GroupPaymentStatus::class, options: ['default' => 'pending'])]
    #[Groups(['group_booking:read', 'group_booking:write'])]
    private GroupPaymentStatus $paymentStatus = GroupPaymentStatus::Pending;

    /** Échéance de l'option : au-delà, la pré-réservation n'est plus tenue. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['group_booking:read', 'group_booking:write'])]
    private ?\DateTimeImmutable $optionExpiresAt = null;

    /**
     * Partie facturée, si elle diffère de l'organisateur : comité d'entreprise, collectivité, mandant.
     * À défaut, le `client` du groupe fait foi.
     */
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['group_booking:read', 'group_booking:write'])]
    private ?Client $payer = null;

    /** Vente rattachée une fois la facturation engagée (chaîne `App\Vente` / `App\Facturation`). */
    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['group_booking:read'])]
    private ?Vente $venteRattachee = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['group_booking:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getGroup(): ?ParticipantGroup
    {
        return $this->group;
    }

    public function setGroup(?ParticipantGroup $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function getCreneau(): ?Creneau
    {
        return $this->creneau;
    }

    public function setCreneau(?Creneau $creneau): self
    {
        $this->creneau = $creneau;

        return $this;
    }

    public function getActivite(): ?Activite
    {
        return $this->activite;
    }

    public function setActivite(?Activite $activite): self
    {
        $this->activite = $activite;

        return $this;
    }

    public function getEffectif(): int
    {
        return $this->effectif;
    }

    public function setEffectif(int $effectif): self
    {
        $this->effectif = $effectif;

        return $this;
    }

    public function getAccompagnateurs(): int
    {
        return $this->accompagnateurs;
    }

    public function setAccompagnateurs(int $accompagnateurs): self
    {
        $this->accompagnateurs = $accompagnateurs;

        return $this;
    }

    public function getStatus(): GroupBookingStatus
    {
        return $this->status;
    }

    public function setStatus(GroupBookingStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getPaymentStatus(): GroupPaymentStatus
    {
        return $this->paymentStatus;
    }

    public function setPaymentStatus(GroupPaymentStatus $paymentStatus): self
    {
        $this->paymentStatus = $paymentStatus;

        return $this;
    }

    public function getOptionExpiresAt(): ?\DateTimeImmutable
    {
        return $this->optionExpiresAt;
    }

    public function setOptionExpiresAt(?\DateTimeImmutable $optionExpiresAt): self
    {
        $this->optionExpiresAt = $optionExpiresAt;

        return $this;
    }

    public function getPayer(): ?Client
    {
        return $this->payer;
    }

    public function setPayer(?Client $payer): self
    {
        $this->payer = $payer;

        return $this;
    }

    public function getVenteRattachee(): ?Vente
    {
        return $this->venteRattachee;
    }

    public function setVenteRattachee(?Vente $venteRattachee): self
    {
        $this->venteRattachee = $venteRattachee;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
