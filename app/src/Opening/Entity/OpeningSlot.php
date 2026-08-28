<?php

declare(strict_types=1);

namespace App\Opening\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\EspaceAcces;
use App\Organisation\Entity\Etablissement;
use App\Opening\State\OpeningWriteProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UNE TRANCHE D'OUVERTURE HEBDOMADAIRE : « le mardi, de 9 h à 21 h ».
 *
 * ── POURQUOI CE MODULE EXISTE ───────────────────────────────────────────────────────────────────
 *
 * Maxime, le 28/08 : *« j'ai pensé qu'il faut un planning d'ouverture (lié au contrôle d'accès s'il
 * y a) »*. Le dépôt savait déjà dire quand une PISCINE a des créneaux, quand un TERRAIN de padel est
 * en heure pleine, quand un EMPLOYÉ travaille — mais nulle part quand **le site est ouvert**. C'est
 * pourtant la donnée dont dépendent les autres : un créneau de bassin à 6 h du matin dans un centre
 * qui ouvre à 8 h est une erreur de saisie que rien ne relevait.
 *
 * ── PLUSIEURS TRANCHES PAR JOUR, ET C'EST LE CAS NORMAL ─────────────────────────────────────────
 *
 * Une piscine municipale ferme entre 13 h et 15 h ; un musée ferme le lundi mais ouvre en nocturne
 * le jeudi. Modéliser « une heure d'ouverture et une heure de fermeture par jour » aurait forcé ces
 * exploitants à déclarer ouvert un midi où ils sont fermés — c'est-à-dire à mentir au contrôle
 * d'accès pour pouvoir s'en servir. On stocke donc des TRANCHES, autant que nécessaire par jour.
 *
 * ── LA TRANCHE QUI TRAVERSE MINUIT ──────────────────────────────────────────────────────────────
 *
 * `heureFin <= heureDebut` signifie que la tranche finit LE LENDEMAIN : 22 h → 02 h est une soirée,
 * pas une erreur de saisie. Le socle porte déjà une capacité `acces_nocturne` ; refuser ce cas
 * aurait rendu le planning inutilisable exactement là où le contrôle d'accès sert le plus.
 *
 * ── L'ESPACE EST FACULTATIF, ET LE PLUS PRÉCIS L'EMPORTE ────────────────────────────────────────
 *
 * `espace = null` : la tranche vaut pour tout l'établissement. `espace` renseigné : elle ne vaut que
 * pour cet espace d'accès. **Dès qu'un espace porte au moins une tranche, c'est SON planning qui le
 * gouverne entièrement** — pas l'union avec celui du site. Une règle « le plus précis l'emporte » se
 * raconte en une phrase ; une règle d'intersection oblige à tenir deux plannings en tête pour
 * répondre à « à quelle heure ferme le bassin ? ».
 */
#[ORM\Entity]
#[ORM\Table(name: 'opening_slot')]
#[ORM\Index(name: 'idx_opening_slot_establishment_weekday', columns: ['establishment_id', 'weekday'])]
#[ApiResource(
    shortName: 'OpeningSlot',
    operations: [
        // `paginationItemsPerPage` cote SERVEUR : `itemsPerPage` envoye par le client est ignore
        // (`pagination_client_items_per_page` = false), et la valeur par defaut est TRENTE. Un
        // planning tronque a trente lignes ne dit pas qu'il l'est.
        new GetCollection(
            security: "is_granted('PERM', 'acces.lire') or is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'reservation.lire')",
            paginationItemsPerPage: 200,
        ),
        new Get(security: "is_granted('PERM', 'acces.lire') or is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'reservation.lire')"),
        new Post(security: "is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'acces.gerer')", processor: OpeningWriteProcessor::class),
        new Patch(security: "is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'acces.gerer')", processor: OpeningWriteProcessor::class),
        new Delete(security: "is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'acces.gerer')"),
    ],
    routePrefix: '/opening',
    normalizationContext: ['groups' => ['opening_slot:read']],
    denormalizationContext: ['groups' => ['opening_slot:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['establishment' => 'exact', 'space' => 'exact', 'day' => 'exact'])]
class OpeningSlot
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['opening_slot:read'])]
    private Uuid $id;

    /**
     * Jamais écrit par le client : `OpeningWriteProcessor` le pose depuis l'établissement
     * actif. Un identifiant d'établissement accepté du corps de requête serait une IDOR — on
     * écrirait les horaires du voisin.
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['opening_slot:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\ManyToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['opening_slot:read', 'opening_slot:write'])]
    private ?EspaceAcces $space = null;

    /** Jour ISO-8601 : 1 = lundi … 7 = dimanche, comme `DateTimeInterface::format('N')`. */
    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 7, notInRangeMessage: 'Le jour doit être compris entre 1 (lundi) et 7 (dimanche).')]
    #[Groups(['opening_slot:read', 'opening_slot:write'])]
    private int $weekday = 1;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['opening_slot:read', 'opening_slot:write'])]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['opening_slot:read', 'opening_slot:write'])]
    private \DateTimeImmutable $endTime;

    /** « Nocturne », « Créneau scolaire »… Facultatif : c'est un repère pour l'exploitant. */
    #[ORM\Column(length: 80, nullable: true)]
    #[Groups(['opening_slot:read', 'opening_slot:write'])]
    private ?string $label = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->startTime = new \DateTimeImmutable('09:00:00');
        $this->endTime = new \DateTimeImmutable('18:00:00');
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

    public function getSpace(): ?EspaceAcces
    {
        return $this->space;
    }

    public function setSpace(?EspaceAcces $space): self
    {
        $this->space = $space;

        return $this;
    }

    public function getWeekday(): int
    {
        return $this->weekday;
    }

    public function setWeekday(int $weekday): self
    {
        $this->weekday = $weekday;

        return $this;
    }

    public function getStartTime(): \DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(\DateTimeImmutable $startTime): self
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): \DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(\DateTimeImmutable $endTime): self
    {
        $this->endTime = $endTime;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /** Vrai si la tranche se termine le lendemain (22 h → 02 h). */
    #[Groups(['opening_slot:read'])]
    public function isOvernight(): bool
    {
        return $this->endTime->format('H:i:s') <= $this->startTime->format('H:i:s');
    }
}
