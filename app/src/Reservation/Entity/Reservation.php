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
use App\Reservation\Enum\StatutPaiementReservation;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Security\ReservationSoiVoter;
use App\Reservation\State\AjouterParticipantProcessor;
use App\Reservation\State\AnnulerReservationProcessor;
use App\Reservation\State\EmargerProcessor;
use App\Reservation\State\ReserverProcessor;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
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

    /**
     * ACT-1 / D16 point 1 — nombre d'unités consommées sur la capacité du créneau : huit couverts
     * pour une table de huit, une place pour un cours. **Distinct des `participants`**, et ils ne
     * doivent pas fusionner : un participant est une personne nommée qui peut payer sa part, une
     * unité est une place occupée. Un restaurant a huit unités et zéro participant nommé.
     *
     * Défaut 1 : c'est ce que valait implicitement chaque réservation avant ce lot, donc l'existant
     * est juste sans reprise de données.
     */
    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Positive(message: 'La quantité réservée doit être un entier strictement positif.')]
    #[Groups(['reservation:read'])]
    private int $quantity = 1;

    #[ORM\ManyToOne(targetEntity: ServiceInclus::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['reservation:read'])]
    private ?ServiceInclus $serviceInclusRef = null;

    /**
     * CQ-3 + CQ-6 — identifiant du droit d'accès de type carte qui a été débité d'une unité à la
     * réservation, ou `null` si la réservation ne consomme pas de carte.
     *
     * **Sans ce champ, aucune restitution n'est possible.** Le décompte a lieu à la réservation ;
     * une annulation doit rendre l'unité, et rien d'autre ne dit sur QUELLE carte la rendre — le
     * porteur peut en avoir plusieurs, et retrouver « celle qui a servi » par déduction serait une
     * devinette. Référence libre (`Uuid`) et non relation : `Reservation` (M5) ne possède pas
     * `App\Acces\Entity\DroitAcces`, même patron que `billetSupportRef`/`produitRef` côté Accès.
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['reservation:read'])]
    private ?Uuid $creditDroitRef = null;

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

    /**
     * ACT-1 point 3 / D33 — les créneaux que cette réservation **consomme** : le créneau visé, plus
     * ceux des ressources ancêtres qui le couvrent dans le temps (le service du soir de la salle
     * au-dessus de la table, le créneau de l'école au-dessus du moniteur).
     *
     * **Le créneau VISÉ reste `$creneau`, et il reste unique** : c'est celui que le client choisit,
     * celui qui s'affiche, celui dont parle RG-M5-01 — elle n'est pas réinterprétée. Ce qui est neuf
     * est la consommation, qui n'était écrite nulle part.
     *
     * **Stocké et non dérivé** (D33) : remonter l'arbre des ressources à chaque contrôle serait plus
     * léger et faux. Ce qu'on relâche doit être exactement ce qu'on a pris, et un contrôle dérivé
     * d'un parcours se course avec lui-même dès deux réservations simultanées sur la même salle.
     *
     * @var Collection<int, Creneau>
     */
    #[ORM\ManyToMany(targetEntity: Creneau::class)]
    #[ORM\JoinTable(name: 'reservation_consumed_slot')]
    // Volontairement hors groupe de sérialisation : personne n'en a besoin côté client aujourd'hui,
    // et l'exposer ferait grossir chaque ligne de liste de réservations d'un créneau imbriqué par
    // ancêtre. On l'ouvrira le jour où un écran le demande (D13 — on n'ajoute pas de surface « au
    // cas où »).
    private Collection $consumedSlots;

    /** @var Collection<int, ParticipantReservation> */
    #[ORM\OneToMany(targetEntity: ParticipantReservation::class, mappedBy: 'reservation', cascade: ['persist'])]
    #[Groups(['reservation:read'])]
    private Collection $participants;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
        $this->participants = new ArrayCollection();
        $this->consumedSlots = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    /** @return Collection<int, Creneau> */
    public function getConsumedSlots(): Collection
    {
        return $this->consumedSlots;
    }

    public function addConsumedSlot(Creneau $creneau): self
    {
        if (!$this->consumedSlots->contains($creneau)) {
            $this->consumedSlots->add($creneau);
        }

        return $this;
    }

    public function getCreditDroitRef(): ?Uuid
    {
        return $this->creditDroitRef;
    }

    public function setCreditDroitRef(?Uuid $creditDroitRef): self
    {
        $this->creditDroitRef = $creditDroitRef;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getCreneau(): ?Creneau
    {
        return $this->creneau;
    }

    public function setCreneau(?Creneau $creneau): self
    {
        $this->creneau = $creneau;
        // D33 — le créneau visé fait TOUJOURS partie des créneaux consommés. L'enregistrer ici plutôt
        // qu'au point d'appel n'est pas une commodité : `Padel` et `Musee` créent des `Reservation`
        // sans passer par `ReserverProcessor`, et une réservation absente de `consumedSlots` serait
        // invisible à la jauge — c'est-à-dire du surbooking. L'invariant tenu par l'entité vaut pour
        // tous les chemins, y compris ceux qui n'existent pas encore.
        if ($creneau !== null) {
            $this->addConsumedSlot($creneau);
        }

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

    /**
     * Statut de paiement observable, dérivé de `modeDecompte` + `venteRattachee.statut` (RG-RESAENC-03) :
     * jamais persisté, source de vérité toujours `Vente.statut`.
     */
    public function statutPaiement(): StatutPaiementReservation
    {
        if ($this->modeDecompte !== ModeDecompteReservation::VenteUnite || $this->venteRattachee === null) {
            return StatutPaiementReservation::SansObjet; // gratuit / quota_formule (G3 préservé)
        }

        return match ($this->venteRattachee->getStatut()) {
            StatutVente::EnCours => StatutPaiementReservation::APayer,
            StatutVente::Validee, StatutVente::AvoirEmis => StatutPaiementReservation::Payee, // §4.3 : avoir_emis reste "payée" (payée puis remboursée)
            StatutVente::Annulee => StatutPaiementReservation::Annulee,
        };
    }

    #[Groups(['reservation:read'])]
    public function getStatutPaiement(): string
    {
        return $this->statutPaiement()->value;
    }
}
