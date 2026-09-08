<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Sepa\Entity\RemiseSepa;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Securite\Entity\Utilisateur;
use App\Sport\State\CancelScheduledDebitProcessor;
use App\Sport\State\ReduceScheduledDebitProcessor;
use App\Sport\State\SimulerRejetProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Échéance de l'échéancier SEPA d'un abonnement (§1.2 du plan). */
#[ORM\Entity]
#[ORM\Table(name: 'sport_echeance_sepa')]
/**
 * ⚠ SANS CE FILTRE, `?abonnement=<id>` ETAIT ACCEPTE ET IGNORE EN SILENCE.
 *
 * API Platform ne refuse pas un parametre de requete inconnu : il le laisse passer et rend la
 * collection ENTIERE. Une fiche d'abonnement qui demandait « les echeances de celui-ci » recevait
 * donc celles de tout le monde, sans erreur, sans avertissement, et avec un code 200.
 *
 * Signale par `allaccess-c0` le 03/09 en construisant la fiche d'abonnement : elle a du trier cote
 * ecran sur l'IRI, ce qui marche tant que la page rend tout — et cesse de marcher des que la
 * pagination coupe. Son bandeau le disait ; ce filtre le rend inutile.
 *
 * ⚠ C'EST LA FAMILLE DES ECRITURES ACCEPTEES QUI N'ENREGISTRENT RIEN, VUE COTE LECTURE. Le
 * garde-fou « filtres declares » surveille l'inverse (un filtre declare sur une propriete qui
 * n'existe pas) ; celui-ci manquait tout court, et rien ne le signalait.
 *
 * `exact` et pas `partial` : un identifiant se compare, il ne se cherche pas.
 */
/**
 * ⚠ SANS CE FILTRE, `?order[dateProgrammee]=asc` ETAIT ACCEPTE ET IGNORE — MEME PIEGE QUE CI-DESSUS.
 *
 * `FicheAbonnement.jsx:95` envoyait ce parametre depuis le 03/09. API Platform rendait 200 et la
 * collection dans l'ordre des UUID. Le defaut se DEGUISAIT donc en corrige : l'appel est la, la
 * reponse est verte, et rien ne dit que le tri n'a pas eu lieu.
 *
 * ⚠ Le temoin qui tranche vient du serveur, pas d'une relecture : le gabarit `search` de la reponse
 * declarait `{?abonnement,abonnement[],statut,statut[]}`. Une ressource dit elle-meme ce qu'elle
 * sait faire — l'interroger coute moins cher que de deduire.
 *
 * Le meme correctif existe depuis longtemps sur `Platform\Entity\Notification` (meme cause, meme
 * remede) : le frere avait ete repare, pas celui-ci.
 */
#[ApiFilter(OrderFilter::class, properties: ['dateProgrammee' => 'ASC'], arguments: ['orderParameterName' => 'order'])]
#[ApiFilter(SearchFilter::class, properties: ['abonnement' => 'exact', 'statut' => 'exact'])]
#[ApiResource(
    shortName: 'EcheanceSepa',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.lire') or is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'sport.lire') or is_granted('PERM', 'compta.lire')"),
        new Post(
            uriTemplate: '/sport/echeances/{id}/simuler-rejet',
            read: true,
            input: false,
            security: "is_granted('PERM', 'recouvrement.piloter')",
            processor: SimulerRejetProcessor::class,
            output: IncidentImpaye::class,
            normalizationContext: ['groups' => ['incident:read']],
        ),
        new Post(
            uriTemplate: '/sport/echeances/{id}/annuler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'sport.gerer_abonnement')",
            processor: CancelScheduledDebitProcessor::class,
        ),
        // UNE OFFRE SUR UN PRÉLÈVEMENT À VENIR — parrainage, geste commercial, mois offert.
        //
        // ⚠ ON POUVAIT ANNULER UNE ÉCHÉANCE, JAMAIS LA RÉDUIRE. Le montant n'avait qu'un seul
        //   écrivain, le générateur d'échéancier, à la création. Faire un geste de 10 € obligeait
        //   donc à annuler tout le prélèvement du mois — ou à ne rien faire.
        new Post(
            uriTemplate: '/sport/echeances/{id}/reduire',
            read: true,
            // Le corps est lu par `LecteurCorps` dans le processeur, comme sur `souscrire`. Sans
            // `input: false`, API Platform desrialiserait le corps et n'accepterait que
            // `application/ld+json` : le serveur aurait rendu 415 a CHAQUE appel.
            //
            // Signale par `garde-fou verifier-formats` avant le premier commit. Son message dit
            // aussi pourquoi on ne corrige pas cote client : « une operation qui ne desrialise pas
            // ne controle pas le Content-Type du tout ; le drapeau y est inerte, et l'ecrire fait
            // croire que l'appel est verifie alors qu'il ne l'est pas ».
            input: false,
            security: "is_granted('PERM', 'sport.gerer_abonnement')",
            processor: ReduceScheduledDebitProcessor::class,
        ),
    ],
    // UN ECHEANCIER SE LIT DANS L'ORDRE, ET PERSONNE NE DEVRAIT AVOIR A LE DEMANDER.
    //
    // ⚠ `OrderFilter` seul ne suffit pas : il rend le tri POSSIBLE, pas acquis. `Sport.jsx`
    // ne passe aucun parametre et recevait donc l'ordre des UUID — 25 lignes de septembre 2025
    // a septembre 2026 entremelees. Un defaut d'affichage la, mais pas seulement : tout calcul
    // qui prend « le premier a venir » par `find` rend alors une echeance QUELCONQUE.
    order: ['dateProgrammee' => 'ASC'],
    normalizationContext: ['groups' => ['echeance:read']],
)]
class EcheanceSepa
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['echeance:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AbonnementFitness::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['echeance:read'])]
    private ?AbonnementFitness $abonnement = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['echeance:read'])]
    private \DateTimeImmutable $dateProgrammee;

    #[ORM\Column]
    #[Assert\Positive]
    #[Groups(['echeance:read'])]
    private int $montantCentimes = 0;

    #[ORM\Column(length: 10, enumType: StatutEcheanceSepa::class, options: ['default' => 'a_venir'])]
    #[Groups(['echeance:read'])]
    private StatutEcheanceSepa $statut = StatutEcheanceSepa::AVenir;

    #[ORM\ManyToOne(targetEntity: RemiseSepa::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['echeance:read'])]
    private ?RemiseSepa $remise = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['echeance:read'])]
    private ?\DateTimeImmutable $dateExecutionReelle = null;

    /**
     * Pourquoi cette échéance a été abandonnée.
     *
     * ⚠ EXIGÉ À L'ÉCRITURE, PAS PROPOSÉ. Une échéance annulée est une somme que le club
     * n'encaissera jamais ; la seule question posée six mois plus tard sera « pourquoi ? », et un
     * état sans motif y répond « on ne sait pas ». Le contrôle vit dans
     * `CancelScheduledDebitProcessor`, seul chemin qui pose `Annulee`.
     *
     * Nullable parce que les échéances qui ne sont pas annulées n'en ont pas.
     */
    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['echeance:read'])]
    private ?string $cancellationReason = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['echeance:read'])]
    private ?\DateTimeImmutable $cancelledAt = null;

    /**
     * LE MONTANT AVANT RÉDUCTION — `null` tant qu'aucune n'a été appliquée.
     *
     * ⚠ C'EST `montantCentimes` QUI PORTE LE MONTANT À PRÉLEVER, ET LA RÉDUCTION LE DIMINUE EN
     * PLACE. Garder la base ici et soustraire chez les lecteurs obligerait à modifier chaque endroit
     * qui lit une échéance — `SportEcheanceSepaSource`, le préavis, la génération de remise,
     * l'écran, la comptabilité — et il suffirait d'en oublier UN pour prélever le plein tarif après
     * avoir annoncé le réduit. Un seul écrivain, aucun lecteur à changer.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['echeance:read'])]
    private ?int $montantInitialCentimes = null;

    /**
     * POURQUOI CE PRÉLÈVEMENT A ÉTÉ RÉDUIT.
     *
     * Exigé, comme le motif d'annulation juste au-dessus et pour la même raison : la seule question
     * posée six mois plus tard, devant un relevé qui ne correspond pas au contrat, sera « pourquoi ».
     */
    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['echeance:read'])]
    private ?string $reductionMotif = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['echeance:read'])]
    private ?\DateTimeImmutable $reductionAt = null;

    /** Qui a consenti le geste. Un rabais sur un prélèvement est une décision, elle a un auteur. */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['echeance:read'])]
    private ?Utilisateur $reductionPar = null;

    /**
     * LA DATE AVANT LAQUELLE CE PRÉLÈVEMENT NE PEUT PLUS PARTIR — servie, jamais persistée.
     *
     * ⚠ CHANGER LE MONTANT REPOUSSE LE PRÉLÈVEMENT, ET IL FAUT LE DIRE AU MOMENT DU GESTE.
     * `DebitPreNotifier::reasonNotCovered()` refuse un prélèvement dont le montant diffère de celui
     * annoncé ; le préavis sera donc réémis, et `announce()` remet `sentAt` à l'instant courant —
     * « un montant qui change doit rendre au client la totalité du délai ». Un geste commercial fait
     * trois jours avant l'échéance la décale donc de deux semaines. C'est correct, et personne ne le
     * devinerait : l'écran l'annonce à partir de ce champ.
     *
     * Nul en lecture ordinaire : il ne vaut que pour la réponse au geste qui vient de l'écrire.
     */
    #[Groups(['echeance:read'])]
    private ?\DateTimeImmutable $prelevementPasAvant = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMontantInitialCentimes(): ?int
    {
        return $this->montantInitialCentimes;
    }

    public function setMontantInitialCentimes(?int $montantInitialCentimes): self
    {
        $this->montantInitialCentimes = $montantInitialCentimes;

        return $this;
    }

    public function getReductionMotif(): ?string
    {
        return $this->reductionMotif;
    }

    public function setReductionMotif(?string $reductionMotif): self
    {
        $this->reductionMotif = $reductionMotif;

        return $this;
    }

    public function getReductionAt(): ?\DateTimeImmutable
    {
        return $this->reductionAt;
    }

    public function setReductionAt(?\DateTimeImmutable $reductionAt): self
    {
        $this->reductionAt = $reductionAt;

        return $this;
    }

    public function getReductionPar(): ?Utilisateur
    {
        return $this->reductionPar;
    }

    public function setReductionPar(?Utilisateur $reductionPar): self
    {
        $this->reductionPar = $reductionPar;

        return $this;
    }

    public function getPrelevementPasAvant(): ?\DateTimeImmutable
    {
        return $this->prelevementPasAvant;
    }

    public function setPrelevementPasAvant(?\DateTimeImmutable $prelevementPasAvant): self
    {
        $this->prelevementPasAvant = $prelevementPasAvant;

        return $this;
    }

    public function getAbonnement(): ?AbonnementFitness
    {
        return $this->abonnement;
    }

    public function setAbonnement(?AbonnementFitness $abonnement): self
    {
        $this->abonnement = $abonnement;

        return $this;
    }

    public function getDateProgrammee(): \DateTimeImmutable
    {
        return $this->dateProgrammee;
    }

    public function setDateProgrammee(\DateTimeImmutable $dateProgrammee): self
    {
        $this->dateProgrammee = $dateProgrammee;

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

    public function getStatut(): StatutEcheanceSepa
    {
        return $this->statut;
    }

    public function setStatut(StatutEcheanceSepa $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getRemise(): ?RemiseSepa
    {
        return $this->remise;
    }

    public function setRemise(?RemiseSepa $remise): self
    {
        $this->remise = $remise;

        return $this;
    }

    public function getDateExecutionReelle(): ?\DateTimeImmutable
    {
        return $this->dateExecutionReelle;
    }

    public function setDateExecutionReelle(?\DateTimeImmutable $dateExecutionReelle): self
    {
        $this->dateExecutionReelle = $dateExecutionReelle;

        return $this;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function setCancellationReason(?string $cancellationReason): self
    {
        $this->cancellationReason = $cancellationReason;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): self
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }
}
