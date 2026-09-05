<?php

declare(strict_types=1);

namespace App\Reservation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\State\AnnulerCreneauProcessor;
use App\Reservation\State\ArbitrerConflitRecurrenceProcessor;
use App\Reservation\State\CreerCreneauProcessor;
use App\Reservation\State\InscrireListeAttenteProcessor;
use App\Reservation\State\ModifierOccurrenceProcessor;
use App\Reservation\State\FreeSlotProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Créneau : occurrence réservable d'une Ressource (RG-M5-01/03). Le conflit de ressource (fenêtres
 * chevauchantes sur la même Ressource) est bloqué à la création (`ChevauchementCreneauGuard`, CA-2).
 * Peut être issu d'une récurrence (RG-M5-07) ; `occurrenceModifiee` marque une exception série.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reservation_creneau')]
#[ORM\Index(columns: ['ressource_id', 'debut', 'fin'], name: 'idx_creneau_ressource_periode')]
#[ApiResource(
    shortName: 'ReservationCreneau',
    operations: [
        // LES DEBUTS POSSIBLES POUR UNE PRESTATION, UN JOUR DONNE (placement libre).
        //
        // Le module savait reserver un creneau QUI EXISTE DEJA. Ce point d'entree rend les debuts
        // ou un rendez-vous TIENDRAIT -- le seul modele possible pour un coiffeur ou un masseur,
        // ou rien n'existe avant que le client n'appelle. Il ne reserve rien : la reservation
        // reste la creation d'un Creneau, protegee par ChevauchementCreneauGuard.
        //
        // Parametres en QUERY et non en segments d'URL : une variable d'URL est convertie par API
        // Platform avant d'atteindre le fournisseur, et une date n'a rien a faire dans un chemin
        // de ressource.
        new GetCollection(
            uriTemplate: '/reservation/creneaux-libres',
            security: "is_granted('PERM', 'reservation.lire')",
            provider: FreeSlotProvider::class,
        ),
        new GetCollection(security: "is_granted('PERM', 'reservation.lire')"),
        new Get(security: "is_granted('PERM', 'reservation.lire')"),
        new Post(
            uriTemplate: '/reservation/creneaux',
            read: false,
            input: false,
            security: "is_granted('PERM', 'reservation.gerer_creneau')",
            processor: CreerCreneauProcessor::class,
        ),
        new Patch(
            uriTemplate: '/reservation/creneaux/{id}',
            security: "is_granted('PERM', 'reservation.gerer_creneau')",
            processor: ModifierOccurrenceProcessor::class,
        ),
        new Post(
            uriTemplate: '/reservation/creneaux/{id}/annuler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'reservation.gerer_creneau')",
            processor: AnnulerCreneauProcessor::class,
        ),
        new Post(
            uriTemplate: '/reservation/creneaux/{id}/arbitrer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'reservation.arbitrer_recurrence')",
            processor: ArbitrerConflitRecurrenceProcessor::class,
        ),
        // Déclarée ici (plutôt que sur ListeAttente) : {id} correspond à l'identifiant propre du
        // Créneau, évitant une variable d'URI secondaire non résolvable nativement par API Platform.
        new Post(
            uriTemplate: '/reservation/creneaux/{id}/liste-attente',
            read: true,
            input: false,
            security: "is_granted('PERM', 'reservation.reserver') or is_granted('PERM', 'reservation.reserver_soi')",
            processor: InscrireListeAttenteProcessor::class,
            output: ListeAttente::class,
            normalizationContext: ['groups' => ['liste_attente:read']],
        ),
    ],
    normalizationContext: ['groups' => ['creneau:read']],
    denormalizationContext: ['groups' => ['creneau:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['ressource' => 'exact', 'activite' => 'exact', 'statut' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['debut'])]
class Creneau
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['creneau:read', 'reservation:read', 'liste_attente:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Ressource::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['creneau:read', 'creneau:write', 'reservation:read'])]
    private ?Ressource $ressource = null;

    #[ORM\ManyToOne(targetEntity: Activite::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['creneau:read', 'creneau:write'])]
    private ?Activite $activite = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['creneau:read', 'creneau:write', 'reservation:read'])]
    private \DateTimeImmutable $debut;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['creneau:read', 'creneau:write', 'reservation:read'])]
    private \DateTimeImmutable $fin;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['creneau:read', 'creneau:write'])]
    private int $capacite = 1;

    #[ORM\Column(length: 10, enumType: StatutCreneau::class, options: ['default' => 'planifie'])]
    #[Groups(['creneau:read'])]
    private StatutCreneau $statut = StatutCreneau::Planifie;

    #[ORM\Column(length: 80, nullable: true)]
    #[Groups(['creneau:read', 'creneau:write'])]
    private ?string $publicReserve = null;

    #[ORM\ManyToOne(targetEntity: Recurrence::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['creneau:read'])]
    private ?Recurrence $recurrence = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['creneau:read'])]
    private bool $occurrenceModifiee = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['creneau:read'])]
    private bool $enAttenteArbitrage = false;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['creneau:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->debut = new \DateTimeImmutable();
        $this->fin = new \DateTimeImmutable('+1 hour');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getRessource(): ?Ressource
    {
        return $this->ressource;
    }

    public function setRessource(?Ressource $ressource): self
    {
        $this->ressource = $ressource;

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

    public function getDebut(): \DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(\DateTimeImmutable $debut): self
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): \DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(\DateTimeImmutable $fin): self
    {
        $this->fin = $fin;

        return $this;
    }

    public function getCapacite(): int
    {
        return $this->capacite;
    }

    public function setCapacite(int $capacite): self
    {
        $this->capacite = $capacite;

        return $this;
    }

    public function getStatut(): StatutCreneau
    {
        return $this->statut;
    }

    public function setStatut(StatutCreneau $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getPublicReserve(): ?string
    {
        return $this->publicReserve;
    }

    public function setPublicReserve(?string $publicReserve): self
    {
        $this->publicReserve = $publicReserve;

        return $this;
    }

    public function getRecurrence(): ?Recurrence
    {
        return $this->recurrence;
    }

    public function setRecurrence(?Recurrence $recurrence): self
    {
        $this->recurrence = $recurrence;

        return $this;
    }

    public function isOccurrenceModifiee(): bool
    {
        return $this->occurrenceModifiee;
    }

    public function setOccurrenceModifiee(bool $occurrenceModifiee): self
    {
        $this->occurrenceModifiee = $occurrenceModifiee;

        return $this;
    }

    public function isEnAttenteArbitrage(): bool
    {
        return $this->enAttenteArbitrage;
    }

    public function setEnAttenteArbitrage(bool $enAttenteArbitrage): self
    {
        $this->enAttenteArbitrage = $enAttenteArbitrage;

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

    /** Montant de référence (tarif) porté par l'activité, base de la vente unité / no-show (§4.2/4.3). */
    /**
     * Le tarif de reference du creneau : celui de la prestation, augmente du supplement du
     * praticien qui l'assure.
     *
     * DEUX LECTEURS, ET LE SUPPLEMENT COULE DANS LES DEUX. `ReserverProcessor` en tire le prix paye,
     * et `DeclencherFacturationNoShowHandler` le montant d'une non-presentation. Une absence sur un
     * rendez-vous a 40 EUR se facture donc sur 40, pas sur 25 : une heure de senior perdue coute ce
     * qu'elle vaut. C'est voulu, et ce n'est visible depuis aucun des deux ecrans -- d'ou cette
     * phrase.
     *
     * PAS DE PRESTATION = PAS DE TARIF, et surtout pas le supplement seul : un creneau sans
     * activite rend '0.00' et bascule la reservation en gratuit. Rendre le supplement ici ferait
     * payer 15 EUR pour une prestation qui n'existe pas.
     */
    public function tarifReference(): string
    {
        $base = $this->activite?->getTarifReferenceMontant();
        if ($base === null) {
            return '0.00';
        }

        $supplement = $this->ressource?->getSupplementTarifMontant() ?? '0.00';

        return number_format((float) $base + (float) $supplement, 2, '.', '');
    }
}
