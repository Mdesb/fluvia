<?php

declare(strict_types=1);

namespace App\Reservation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Reservation\Enum\IssueCreditNoShow;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\State\EmettreVenteNoShowProcessor;
use App\Reservation\State\ExonererProcessor;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Facturation d'un no-show / annulation tardive (RG-M5-09). ⚠ Point d'architecture majeur (spec §8) :
 * le statut ne garantit pas systématiquement un encaissement effectif (modes `prelevement_differe`/
 * `facture_a_encaisser` = squelettes déclaratifs).
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_facturation_no_show')]
#[ORM\UniqueConstraint(name: 'uniq_facturation_no_show_reservation', columns: ['reservation_id'])]
#[ApiResource(
    shortName: 'ReservationFacturationNoShow',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire') or is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'reservation.lire') or is_granted('PERM', 'compta.lire')"),
        new Post(
            uriTemplate: '/reservation/facturations-no-show/{id}/exonerer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'reservation.exonerer')",
            processor: ExonererProcessor::class,
        ),
        new Post(
            uriTemplate: '/reservation/facturations-no-show/{id}/emettre-vente',
            read: true,
            input: false,
            security: "is_granted('PERM', 'reservation.facturer')",
            processor: EmettreVenteNoShowProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['facturation_no_show:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['statut' => 'exact', 'reservation' => 'exact'])]
class FacturationNoShow
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['facturation_no_show:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['facturation_no_show:read'])]
    private ?Reservation $reservation = null;

    #[ORM\ManyToOne(targetEntity: RegleAnnulation::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['facturation_no_show:read'])]
    private ?RegleAnnulation $regleAppliquee = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['facturation_no_show:read'])]
    private string $montant = '0.00';

    #[ORM\Column(length: 12, enumType: StatutFacturationNoShow::class, options: ['default' => 'a_facturer'])]
    #[Groups(['facturation_no_show:read'])]
    private StatutFacturationNoShow $statut = StatutFacturationNoShow::AFacturer;

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['facturation_no_show:read'])]
    private ?Vente $venteRattachee = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['facturation_no_show:read'])]
    private ?string $referenceEcheanceSepa = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['facturation_no_show:read'])]
    private ?Utilisateur $exonerePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['facturation_no_show:read'])]
    private ?string $motifExoneration = null;

    /**
     * Copie figée de l'`IssueCreditNoShow` réellement appliquée (RG-CQ5-06) — une règle peut être
     * reparamétrée après coup, ce champ trace ce qui a été décidé, pas ce que dirait la règle si on la
     * relisait aujourd'hui. `null` pour les lignes historiques (avant ce lot) et pour les no-show sans
     * règle active (RG-CQ5-03).
     */
    #[ORM\Column(length: 24, nullable: true, enumType: IssueCreditNoShow::class)]
    #[Groups(['facturation_no_show:read'])]
    private ?IssueCreditNoShow $issueCreditNoShow = null;

    /** Un `DroitAcces` créditable a-t-il été trouvé pour cette réservation ? (RG-CQ5-04) */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['facturation_no_show:read'])]
    private bool $creditActionne = false;

    /** `true` seulement si `Restored`/`RestoredWithReschedule` et l'`UPDATE` a effectivement affecté une ligne. */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['facturation_no_show:read'])]
    private bool $creditRestitue = false;

    /**
     * Référence du `DroitAcces` effectivement restitué — TRANSIENT, jamais persisté (aucun
     * `#[ORM\Column]`, plan-cq5.md §3.3). Peuplé uniquement dans le même appel PHP que
     * `ApplyNoShowCreditIssueHandler::apply()` (`DeclencherFacturationNoShowHandler::declencher()`),
     * pour que l'appelant (`BasculerNoShowCommand`/`AnnulerReservationProcessor`) puisse construire le
     * payload de `booking.reschedule_requested` SANS relire `ProjectionAccesReservation` une seconde
     * fois. Redevient `null` après un `find()`/`refresh()` ultérieur — usage strictement synchrone,
     * jamais lu après un `em->clear()`.
     */
    private ?Uuid $droitAccesRestitueRef = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): self
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function getRegleAppliquee(): ?RegleAnnulation
    {
        return $this->regleAppliquee;
    }

    public function setRegleAppliquee(?RegleAnnulation $regleAppliquee): self
    {
        $this->regleAppliquee = $regleAppliquee;

        return $this;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = $montant;

        return $this;
    }

    public function getStatut(): StatutFacturationNoShow
    {
        return $this->statut;
    }

    public function setStatut(StatutFacturationNoShow $statut): self
    {
        $this->statut = $statut;

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

    public function getReferenceEcheanceSepa(): ?string
    {
        return $this->referenceEcheanceSepa;
    }

    public function setReferenceEcheanceSepa(?string $referenceEcheanceSepa): self
    {
        $this->referenceEcheanceSepa = $referenceEcheanceSepa;

        return $this;
    }

    public function getExonerePar(): ?Utilisateur
    {
        return $this->exonerePar;
    }

    public function setExonerePar(?Utilisateur $exonerePar): self
    {
        $this->exonerePar = $exonerePar;

        return $this;
    }

    public function getMotifExoneration(): ?string
    {
        return $this->motifExoneration;
    }

    public function setMotifExoneration(?string $motifExoneration): self
    {
        $this->motifExoneration = $motifExoneration;

        return $this;
    }

    public function getIssueCreditNoShow(): ?IssueCreditNoShow
    {
        return $this->issueCreditNoShow;
    }

    public function setIssueCreditNoShow(?IssueCreditNoShow $issueCreditNoShow): self
    {
        $this->issueCreditNoShow = $issueCreditNoShow;

        return $this;
    }

    public function isCreditActionne(): bool
    {
        return $this->creditActionne;
    }

    public function setCreditActionne(bool $creditActionne): self
    {
        $this->creditActionne = $creditActionne;

        return $this;
    }

    public function isCreditRestitue(): bool
    {
        return $this->creditRestitue;
    }

    public function setCreditRestitue(bool $creditRestitue): self
    {
        $this->creditRestitue = $creditRestitue;

        return $this;
    }

    public function getDroitAccesRestitueRef(): ?Uuid
    {
        return $this->droitAccesRestitueRef;
    }

    public function setDroitAccesRestitueRef(?Uuid $droitAccesRestitueRef): self
    {
        $this->droitAccesRestitueRef = $droitAccesRestitueRef;

        return $this;
    }

    /**
     * RG-CQ5-09 — dégradation D27 : signale qu'un signal (`booking.reschedule_requested`) a
     * réellement été publié pour cette facturation, jamais qu'un créneau va être proposé (Smart
     * Flow/SF-2 absent). `true` si et seulement si l'événement a réellement été publié — miroir exact
     * de la condition de publication côté appelant (`BasculerNoShowCommand`/`AnnulerReservationProcessor`).
     */
    #[Groups(['facturation_no_show:read'])]
    public function isRescheduleRequested(): bool
    {
        return $this->issueCreditNoShow === IssueCreditNoShow::RestoredWithReschedule
            && $this->creditActionne
            && $this->creditRestitue;
    }
}
