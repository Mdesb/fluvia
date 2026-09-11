<?php

declare(strict_types=1);

namespace App\Membership\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Offre\Entity\Formule;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Sepa\Entity\MandatSepa;
use App\Membership\Enum\MembershipPeriodicity;
use App\Membership\Enum\MembershipStatus;
use App\Membership\State\DemanderPauseProcessor;
use App\Membership\State\DemanderResiliationProcessor;
use App\Membership\State\RattacherDroitAccesProcessor;
use App\Membership\State\ReengagerProcessor;
use App\Membership\State\SouscrireAbonnementProcessor;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use App\Sepa\Validator\NoticeDelayCoversPeriod;
use Symfony\Component\Uid\Uuid;

/**
 * L'abonnement d'un adhérent — sorti de `App\Sport` le 10/09 (lot 1 de la bascule transverse).
 *
 * ── ⚠ DEUX NOMS N'ONT PAS SUIVI LA CLASSE, ET LES DEUX SONT DÉLIBÉRÉS ─────────────────────────
 *
 * **La table s'appelle toujours `sport_abonnement_fitness`.** Arbitrage de Maxime : « déplace sans
 * renommer ». Ce n'est pas de la paresse, c'est un comptage : **sept clés étrangères** pointent sur
 * cette table — échéances, mouvements comptables, pauses, résiliations, les deux du réengagement,
 * statuts d'accès. Un renommage les emmène toutes, et impose un déploiement où le code et le schéma
 * basculent au même instant. Le renommage reste possible plus tard, isolé, décidé pour lui-même.
 *
 * **La ressource d'API s'appelle toujours `AbonnementFitness`**, donc la route reste
 * `/api/abonnement_fitnesses`. La renommer casserait le frontal, et deux branches front touchent ces
 * écrans en ce moment. Même raison, même report.
 *
 * ⚠ CONSÉQUENCE À CONNAÎTRE AVANT DE « CORRIGER » QUOI QUE CE SOIT ICI : la classe, la table et la
 * ressource portent trois noms différents. C'est un état de transition assumé. Aligner l'un des
 * trois sans les deux autres casse soit les clés étrangères, soit l'écran.
 *
 * **Les valeurs d'énumération restent en français** — `actif`, `mensuel`… Ce sont des codes
 * PERSISTÉS, et la table ne bouge pas. `MembershipStatus::Echu` documente déjà ce choix à
 * contre-courant de D5 ; il vaut pour toute la famille.
 *
 * ── CE QUE L'ENTITÉ EST ────────────────────────────────────────────────────────────────────────
 *
 * Abonnement récurrent (US-SPORT-01, RG-M1-03). Instancie une `Formule` M1 (existante, lue non
 * modifiée) en fixant périodicité SEPA/engagement/mandat. Pilote la projection d'accès L3 (§0 du
 * plan) via `StatutAccesFitness` et `PropagationAccesFitnessHandler` — jamais d'écriture directe sur
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
    // Fermeture **declaree** de la denormalisation (D41). Aucune propriete ne porte
    // `abonnement:write` : rien n'est ecrivable depuis le corps. Les creations portent deja
    // `input: false`, qui suffit techniquement — mais le garde-fou n12 ne sait pas le lire
    // et compterait l'entite comme exposee indefiniment. Une fermeture qu'aucun outil ne
    // voit finit par etre "corrigee" une seconde fois par quelqu'un d'autre.
    denormalizationContext: ['groups' => ['abonnement:write']],
)]
#[NoticeDelayCoversPeriod]
/*
 * ⚠ UN FILTRE NON DECLARE EST IGNORE EN SILENCE, ET L'ENDPOINT REND TOUT.
 *
 *    Un client doit retrouver, sur sa fiche, les abonnements qu'il PAIE et ceux qui sont a SON
 * nom : deux questions differentes, deux relations differentes. Sans filtre declare, l'ecran
 * devrait tout charger et trier lui-meme -- ce qui marche a onze abonnements et ment a mille.
 *
 * Ca ressemble a des donnees, ca arrive, ca a la bonne forme -- et personne ne le remet en
 * cause. C'est le motif que `bin/garde-fou-filtres-declares.php` surveille.
 */
#[ApiFilter(SearchFilter::class, properties: ['payeur' => 'exact', 'adherent' => 'exact', 'etablissement' => 'exact', 'statut' => 'exact'])]
class Membership
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

    #[ORM\Column(length: 12, enumType: MembershipPeriodicity::class)]
    #[Groups(['abonnement:read'])]
    private MembershipPeriodicity $periodicite = MembershipPeriodicity::Mensuel;

    #[ORM\Column(length: 12, enumType: MembershipStatus::class, options: ['default' => 'actif'])]
    #[Groups(['abonnement:read'])]
    private MembershipStatus $statut = MembershipStatus::Actif;

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

    /**
     * LE MONTANT COURANT DE L'ABONNEMENT, EN CENTIMES.
     *
     * ── ⚠ ET L'ÉCHÉANCE FAIT FOI, PAS CE CHAMP ────────────────────────────────────────────────
     *
     * Le prix vivait DÉJÀ quelque part avant ce champ : `GenerateurEcheancierHandler` l'estampille
     * sur chaque `EcheanceSepa` au moment de la souscription, et il n'est jamais relu ensuite.
     * Poser un montant ici crée donc une SECONDE source pour le même fait, et il faut dire laquelle
     * gagne avant qu'elles ne divergent — sans quoi personne ne saura laquelle est fausse le jour
     * d'un changement de tarif.
     *
     * **L'échéance fait foi.** Elle est datée, émise, et opposable : c'est elle qui sera prélevée.
     * Ce champ ne porte que le montant COURANT — ce qu'on facturera la prochaine fois, ce qu'un
     * écran affiche quand on demande « combien coûte cet abonnement », et ce qu'on compare pour
     * décider d'un changement de tarif. Il ne réécrit jamais une échéance déjà générée.
     *
     * ⚠ CONSÉQUENCE À TENIR : changer ce montant ne change PAS les échéances futures déjà posées.
     * Le jour où un écran le modifiera, il devra régénérer explicitement ce qui n'est pas encore
     * remis en banque — et ce geste-là se décide, il ne se déduit pas d'une écriture de champ.
     *
     * ⚠ `DEFAULT` DÉCLARÉ AU MAPPING, et pas seulement dans la migration : le déploiement migre
     * avant de redémarrer PHP, donc il existe une fenêtre où le schéma porte la colonne et où le
     * code ancien insère sans la renseigner. Sans défaut, cette fenêtre rend des erreurs SQL.
     *
     * ⚠ ET LA FORMULE NE PROPOSE RIEN, aujourd'hui : `Offre\Entity\Formule` ne porte AUCUN prix —
     * ni champ, ni relation vers un tarif. Le montant est donc entièrement à la charge de
     * l'appelant, comme il l'était déjà. Faire de la formule la source du défaut demanderait de
     * lui ajouter un prix, ce qui est une décision distincte et une troisième source.
     */
    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['abonnement:read'])]
    private int $montantCentimes = 0;

    /**
     * ⚠ `ManyToOne` ET NON `OneToOne` : un même mandat porte plusieurs abonnements.
     *
     * Un adulte et son enfant, deux formules dans la même famille — le payeur donne son IBAN une
     * fois. Deux mandats pour le même débiteur chez le même créancier ne sont pas la logique SEPA :
     * un mandat autorise à prélever, il n'est pas attaché à ce qu'on facture. Le mandat était déjà
     * générique de son côté (rattaché au client et à l'établissement) ; c'est ce lien-ci qui
     * imposait l'unicité.
     *
     * ⚠ ET CE CHANGEMENT SEUL SERAIT UN DÉFAUT. `DemanderResiliationHandler::executerEffet()`
     * révoquait le mandat sans condition, ce qui était correct tant qu'il n'appartenait qu'à un
     * abonnement. La révocation conditionnelle est partie dans le même commit : les séparer aurait
     * arrêté les prélèvements du second abonnement sans erreur ni message.
     */
    #[ORM\ManyToOne(targetEntity: MandatSepa::class)]
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

    public function getPeriodicite(): MembershipPeriodicity
    {
        return $this->periodicite;
    }

    public function setPeriodicite(MembershipPeriodicity $periodicite): self
    {
        $this->periodicite = $periodicite;

        return $this;
    }

    public function getStatut(): MembershipStatus
    {
        return $this->statut;
    }

    public function setStatut(MembershipStatus $statut): self
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

    public function getMontantCentimes(): int
    {
        return $this->montantCentimes;
    }

    public function setMontantCentimes(int $montantCentimes): self
    {
        $this->montantCentimes = $montantCentimes;

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
