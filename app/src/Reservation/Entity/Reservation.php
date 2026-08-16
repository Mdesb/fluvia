<?php

declare(strict_types=1);

namespace App\Reservation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Crm\Entity\Beneficiaire;
use App\Offre\Entity\ServiceInclus;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\SourcePresence;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Security\ReservationSoiVoter;
use App\Reservation\State\AjouterParticipantProcessor;
use App\Reservation\State\AnnulerReservationProcessor;
use App\Reservation\State\EmargerProcessor;
use App\Reservation\State\ReserverProcessor;
use App\Vente\Entity\Vente;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Réservation : cycle de vie complet (RG-M5-01/02/09). Décompte le quota inclus d'une formule (M1)
 * si disponible, sinon déclenche une vente à l'unité (M2). `dateLimiteAnnulation` = début du créneau
 * − délai franc de la `RegleAnnulation` résolue (§4.7).
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_reservation')]
#[ApiResource(
    shortName: 'Reservation',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reservation.lire') or is_granted('PERM', 'reservation.lire_soi')"),
        new Get(security: "is_granted('PERM', 'reservation.lire') or (is_granted('PERM', 'reservation.lire_soi') and is_granted('" . ReservationSoiVoter::ATTRIBUTE . "', object))"),
        new Post(
            uriTemplate: '/reservation/reservations',
            security: "is_granted('PERM', 'reservation.reserver') or is_granted('PERM', 'reservation.reserver_soi')",
            processor: ReserverProcessor::class,
        ),
        new Post(
            uriTemplate: '/reservation/reservations/{id}/annuler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'reservation.annuler') or (is_granted('PERM', 'reservation.annuler_soi') and is_granted('" . ReservationSoiVoter::ATTRIBUTE . "', object))",
            processor: AnnulerReservationProcessor::class,
        ),
        new Post(
            uriTemplate: '/reservation/reservations/{id}/participants',
            read: true,
            input: false,
            security: "is_granted('PERM', 'reservation.reserver') or (is_granted('PERM', 'reservation.reserver_soi') and is_granted('" . ReservationSoiVoter::ATTRIBUTE . "', object))",
            processor: AjouterParticipantProcessor::class,
        ),
        // Déclarée ici (plutôt que sur Emargement) : {id} correspond à l'identifiant propre de la
        // Réservation, évitant une variable d'URI secondaire non résolvable nativement par API Platform.
        new Post(
            uriTemplate: '/reservation/reservations/{id}/emarger',
            read: true,
            input: false,
            security: "is_granted('PERM', 'reservation.emarger')",
            processor: EmargerProcessor::class,
            output: Emargement::class,
            normalizationContext: ['groups' => ['emargement:read']],
        ),
    ],
    normalizationContext: ['groups' => ['reservation:read']],
    denormalizationContext: ['groups' => ['reservation:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['creneau' => 'exact', 'organisateur' => 'exact', 'statut' => 'exact'])]
class Reservation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['reservation:read', 'participant:read', 'liste_attente:read', 'facturation_no_show:read', 'emargement:read', 'projection_acces:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Creneau::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reservation:read', 'reservation:write'])]
    private ?Creneau $creneau = null;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reservation:read', 'reservation:write'])]
    private ?Beneficiaire $organisateur = null;

    #[ORM\Column(length: 24, enumType: StatutReservation::class, options: ['default' => 'confirmee'])]
    #[Groups(['reservation:read'])]
    private StatutReservation $statut = StatutReservation::Confirmee;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['reservation:read'])]
    private \DateTimeImmutable $dateCreation;

    #[ORM\Column(length: 14, enumType: ModeDecompteReservation::class)]
    #[Groups(['reservation:read'])]
    private ModeDecompteReservation $modeDecompte = ModeDecompteReservation::Gratuit;

    #[ORM\ManyToOne(targetEntity: ServiceInclus::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['reservation:read'])]
    private ?ServiceInclus $serviceInclusRef = null;

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['reservation:read'])]
    private ?Vente $venteRattachee = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['reservation:read'])]
    private string $montantDu = '0.00';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['reservation:read'])]
    private ?\DateTimeImmutable $dateLimiteAnnulation = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['reservation:read'])]
    private bool $presenceConfirmee = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['reservation:read'])]
    private ?\DateTimeImmutable $dateConfirmationPresence = null;

    #[ORM\Column(length: 18, enumType: SourcePresence::class, nullable: true)]
    #[Groups(['reservation:read'])]
    private ?SourcePresence $sourcePresence = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reservation:read'])]
    private ?Etablissement $etablissement = null;

    /** @var Collection<int, ParticipantReservation> */
    #[ORM\OneToMany(targetEntity: ParticipantReservation::class, mappedBy: 'reservation', cascade: ['persist'])]
    #[Groups(['reservation:read'])]
    private Collection $participants;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
        $this->participants = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getOrganisateur(): ?Beneficiaire
    {
        return $this->organisateur;
    }

    public function setOrganisateur(?Beneficiaire $organisateur): self
    {
        $this->organisateur = $organisateur;

        return $this;
    }

    public function getStatut(): StatutReservation
    {
        return $this->statut;
    }

    public function setStatut(StatutReservation $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getModeDecompte(): ModeDecompteReservation
    {
        return $this->modeDecompte;
    }

    public function setModeDecompte(ModeDecompteReservation $modeDecompte): self
    {
        $this->modeDecompte = $modeDecompte;

        return $this;
    }

    public function getServiceInclusRef(): ?ServiceInclus
    {
        return $this->serviceInclusRef;
    }

    public function setServiceInclusRef(?ServiceInclus $serviceInclusRef): self
    {
        $this->serviceInclusRef = $serviceInclusRef;

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

    public function getMontantDu(): string
    {
        return $this->montantDu;
    }

    public function setMontantDu(string $montantDu): self
    {
        $this->montantDu = $montantDu;

        return $this;
    }

    public function getDateLimiteAnnulation(): ?\DateTimeImmutable
    {
        return $this->dateLimiteAnnulation;
    }

    public function setDateLimiteAnnulation(?\DateTimeImmutable $dateLimiteAnnulation): self
    {
        $this->dateLimiteAnnulation = $dateLimiteAnnulation;

        return $this;
    }

    public function isPresenceConfirmee(): bool
    {
        return $this->presenceConfirmee;
    }

    public function setPresenceConfirmee(bool $presenceConfirmee): self
    {
        $this->presenceConfirmee = $presenceConfirmee;

        return $this;
    }

    public function getDateConfirmationPresence(): ?\DateTimeImmutable
    {
        return $this->dateConfirmationPresence;
    }

    public function setDateConfirmationPresence(?\DateTimeImmutable $dateConfirmationPresence): self
    {
        $this->dateConfirmationPresence = $dateConfirmationPresence;

        return $this;
    }

    public function getSourcePresence(): ?SourcePresence
    {
        return $this->sourcePresence;
    }

    public function setSourcePresence(?SourcePresence $sourcePresence): self
    {
        $this->sourcePresence = $sourcePresence;

        return $this;
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

    /** @return Collection<int, ParticipantReservation> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(ParticipantReservation $participant): self
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
            $participant->setReservation($this);
        }

        return $this;
    }

    public function confirmerPresence(SourcePresence $source, \DateTimeImmutable $date): self
    {
        $this->presenceConfirmee = true;
        $this->dateConfirmationPresence = $date;
        $this->sourcePresence = $source;

        return $this;
    }
}
