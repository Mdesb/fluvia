<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Offre\Entity\Formule;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Sepa\Entity\MandatSepa;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Enum\StatutAbonnementFitness;
use App\Sport\State\DemanderPauseProcessor;
use App\Sport\State\DemanderResiliationProcessor;
use App\Sport\State\RattacherDroitAccesProcessor;
use App\Sport\State\ReengagerProcessor;
use App\Sport\State\SouscrireAbonnementProcessor;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Abonnement fitness récurrent (US-SPORT-01, RG-M1-03). Instancie une `Formule` M1 (existante, lue non
 * modifiée) en fixant périodicité SEPA/engagement/mandat. Pilote la projection d'accès L3 (§0 du plan)
 * via `StatutAccesFitness` et `PropagationAccesFitnessHandler` — jamais d'écriture directe sur
 * `DroitAcces` depuis les handlers métier. Porte aussi les sous-ressources de transition d'état
 * (pause, résiliation, réengagement, rattachement du droit d'accès) — même patron que
 * `Consentement`/`PorteMonnaieVirtuel` en M4 : éviter un `{id}` d'URI ambigu sur l'enfant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_abonnement_fitness')]
#[ApiResource(
    shortName: 'AbonnementFitness',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.lire')"),
        new Get(security: "is_granted('PERM', 'sport.lire') or (is_granted('PERM', 'sport.lire_soi') and object.estLieA(user))"),
        new Post(
            uriTemplate: '/sport/abonnements/souscrire',
            read: false,
            input: false,
            security: "is_granted('PERM', 'sport.gerer_abonnement')",
            processor: SouscrireAbonnementProcessor::class,
        ),
        new Post(
            uriTemplate: '/sport/abonnements/{id}/rattacher-droit-acces',
            read: true,
            input: false,
            security: "is_granted('PERM', 'sport.gerer_abonnement') or is_granted('PERM', 'acces.appairer')",
            processor: RattacherDroitAccesProcessor::class,
        ),
        new Post(
            uriTemplate: '/sport/abonnements/{id}/reengager',
            read: true,
            input: false,
            security: "is_granted('PERM', 'sport.gerer_abonnement')",
            processor: ReengagerProcessor::class,
            output: Reengagement::class,
            normalizationContext: ['groups' => ['reengagement:read']],
        ),
        new Post(
            uriTemplate: '/sport/abonnements/{id}/pauses',
            read: true,
            input: false,
            security: "is_granted('PERM', 'sport.gerer_abonnement') or (is_granted('PERM', 'sport.pause_demander_soi') and object.estLieA(user))",
            processor: DemanderPauseProcessor::class,
            output: PauseAbonnement::class,
            normalizationContext: ['groups' => ['pause:read']],
        ),
        new Post(
            uriTemplate: '/sport/abonnements/{id}/resiliations',
            read: true,
            input: false,
            security: "is_granted('PERM', 'sport.gerer_abonnement') or (is_granted('PERM', 'sport.resilier_demander_soi') and object.estLieA(user))",
            processor: DemanderResiliationProcessor::class,
            output: Resiliation::class,
            normalizationContext: ['groups' => ['resiliation:read']],
        ),
    ],
    normalizationContext: ['groups' => ['abonnement:read']],
)]
class AbonnementFitness
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['abonnement:read', 'pause:read', 'resiliation:read', 'incident:read', 'echeance:read', 'statut_acces:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['abonnement:read'])]
    private ?Beneficiaire $adherent = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['abonnement:read'])]
    private ?Client $payeur = null;

    // Pas de #[Groups] ici : `Formule` (M1) n'est pas un `#[ApiResource]` indépendant (facette de
    // `Produit`, cf. plan §1 en-tête) — une IRI ne peut pas être générée. Seul l'identifiant est
    // exposé (`getFormuleId()` ci-dessous), l'objet complet reste consultable via `GET /produits`.
    #[ORM\ManyToOne(targetEntity: Formule::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Formule $formule = null;

    #[ORM\Column(length: 12, enumType: PeriodiciteAbonnementFitness::class)]
    #[Groups(['abonnement:read'])]
    private PeriodiciteAbonnementFitness $periodicite = PeriodiciteAbonnementFitness::Mensuel;

    #[ORM\Column(length: 12, enumType: StatutAbonnementFitness::class, options: ['default' => 'actif'])]
    #[Groups(['abonnement:read'])]
    private StatutAbonnementFitness $statut = StatutAbonnementFitness::Actif;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['abonnement:read'])]
    private \DateTimeImmutable $dateSouscription;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['abonnement:read'])]
    private \DateTimeImmutable $dateDebutEngagement;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['abonnement:read'])]
    private \DateTimeImmutable $dateFinEngagement;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['abonnement:read'])]
    private int $preavisResiliationJours = 30;

    #[ORM\OneToOne(targetEntity: MandatSepa::class)]
    #[ORM\JoinColumn(name: 'mandat_sepa_id', nullable: false)]
    #[Groups(['abonnement:read'])]
    private ?MandatSepa $mandatSepa = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['abonnement:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAdherent(): ?Beneficiaire
    {
        return $this->adherent;
    }

    public function setAdherent(?Beneficiaire $adherent): self
    {
        $this->adherent = $adherent;

        return $this;
    }

    public function getPayeur(): ?Client
    {
        return $this->payeur;
    }

    public function setPayeur(?Client $payeur): self
    {
        $this->payeur = $payeur;

        return $this;
    }

    public function getFormule(): ?Formule
    {
        return $this->formule;
    }

    public function setFormule(?Formule $formule): self
    {
        $this->formule = $formule;

        return $this;
    }

    #[Groups(['abonnement:read'])]
    public function getFormuleId(): ?string
    {
        return $this->formule?->getId() !== null ? (string) $this->formule->getId() : null;
    }

    public function getPeriodicite(): PeriodiciteAbonnementFitness
    {
        return $this->periodicite;
    }

    public function setPeriodicite(PeriodiciteAbonnementFitness $periodicite): self
    {
        $this->periodicite = $periodicite;

        return $this;
    }

    public function getStatut(): StatutAbonnementFitness
    {
        return $this->statut;
    }

    public function setStatut(StatutAbonnementFitness $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateSouscription(): \DateTimeImmutable
    {
        return $this->dateSouscription;
    }

    public function setDateSouscription(\DateTimeImmutable $dateSouscription): self
    {
        $this->dateSouscription = $dateSouscription;

        return $this;
    }

    public function getDateDebutEngagement(): \DateTimeImmutable
    {
        return $this->dateDebutEngagement;
    }

    public function setDateDebutEngagement(\DateTimeImmutable $dateDebutEngagement): self
    {
        $this->dateDebutEngagement = $dateDebutEngagement;

        return $this;
    }

    public function getDateFinEngagement(): \DateTimeImmutable
    {
        return $this->dateFinEngagement;
    }

    public function setDateFinEngagement(\DateTimeImmutable $dateFinEngagement): self
    {
        $this->dateFinEngagement = $dateFinEngagement;

        return $this;
    }

    public function getPreavisResiliationJours(): int
    {
        return $this->preavisResiliationJours;
    }

    public function setPreavisResiliationJours(int $preavisResiliationJours): self
    {
        $this->preavisResiliationJours = $preavisResiliationJours;

        return $this;
    }

    public function getMandatSepa(): ?MandatSepa
    {
        return $this->mandatSepa;
    }

    public function setMandatSepa(?MandatSepa $mandatSepa): self
    {
        $this->mandatSepa = $mandatSepa;

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

    /** Vrai si l'utilisateur connecté correspond à l'adhérent ou au payeur (permissions `_soi`). */
    public function estLieA(mixed $user): bool
    {
        if (!$user instanceof Utilisateur) {
            return false;
        }
        $lie = $user->getClientLie();
        if ($lie === null) {
            return false;
        }
        $adherentClient = $this->adherent?->getClient();
        if ($adherentClient !== null && (string) $adherentClient->getId() === (string) $lie) {
            return true;
        }

        return $this->payeur !== null && (string) $this->payeur->getId() === (string) $lie;
    }
}
