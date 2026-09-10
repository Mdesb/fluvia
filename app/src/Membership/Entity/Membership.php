<?php

declare(strict_types=1);

namespace App\Membership\Entity;

use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Membership\Enum\MembershipPeriodicity;
use App\Membership\Enum\MembershipStatus;
use App\Offre\Entity\Formule;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\MandatSepa;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * L'abonnement d'un adhérent, sorti du module Sport — **socle du lot 0**.
 *
 * Structure reprise de `App\Sport\Entity\AbonnementFitness` : mêmes treize colonnes, mêmes
 * cardinalités, mêmes cibles de relation. Ce qui change est le nom des choses (D5) et le module qui
 * les porte.
 *
 * ── ⚠ CE QUE CETTE ENTITÉ N'EST PAS ENCORE ─────────────────────────────────────────────────────
 *
 * Elle n'est **pas exposée par l'API** et ne porte aucun groupe de sérialisation. Ce n'est pas un
 * oubli : la spec dit « aucune bascule encore » au lot 0, et une ressource exposée que rien
 * n'appelle EST une bascule, à moitié. Le garde-fou d'écart client/serveur mesure par ailleurs 504
 * opérations inatteignables pour un plafond de 521 — dix-sept crans de marge, qu'on ne dépense pas
 * pour un lot qui n'a pas d'écran.
 *
 * Elle ne porte **aucun comportement** non plus. Ce que fait un abonnement — s'échelonner, se
 * mettre en pause, se résilier, couper un accès — vit dans les handlers Sport, qui se recâblent au
 * lot 1. Les recopier ici poserait deux implémentations de la même règle, dont une que personne
 * n'appelle.
 *
 * ── ⚠ `etablissement` EST EN FRANÇAIS, ET IL DOIT L'ÊTRE ───────────────────────────────────────
 *
 * Seule entorse à D5 de tout le module, et elle est imposée par l'outillage : les extensions de
 * périmètre écrivent `IDENTITY(%s.etablissement)` EN DUR. Une entité qui nommerait cette relation
 * `establishment` sortirait du filtre de cloisonnement **sans aucune erreur Doctrine** — donc sans
 * symptôme, jusqu'au jour où un exploitant lit les données d'un autre. Le garde-fou n°28 refuse
 * cette combinaison précisément pour ça. `App\Group`, module au nommage anglais, fait le même choix.
 *
 * Le garde-fou de nommage ne s'y oppose pas : les propriétés sont hors de son périmètre, et
 * `#[ORM\JoinColumn(name: …)]` n'est pas contrôlé — seul `#[ORM\Column(name: …)]` l'est.
 *
 * ── ⚠ LE MANDAT EST UN `ManyToOne`, ET SON INDEX N'EST PAS UNIQUE ──────────────────────────────
 *
 * La migration d'origine de `sport_abonnement_fitness` (`Version20260815114107`) avait posé un
 * `UNIQUE INDEX` sur `mandat_sepa_id`. Il a fallu le retirer le 01/09 : **un même mandat porte
 * plusieurs abonnements** — un parent qui paie pour deux enfants signe un mandat, pas deux. La table
 * neuve ne refait pas l'erreur.
 */
#[ORM\Entity]
#[ORM\Table(name: 'membership')]
class Membership
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** Celui qui entre. Distinct du payeur : deux questions différentes, deux types différents. */
    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Beneficiaire $member = null;

    /** Celui qui règle. C'est lui que le mandat SEPA débite. */
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Client $payer = null;

    /**
     * L'offre du catalogue. C'est elle — et non l'appelant — qui décide du prix et de la cadence :
     * arbitrage « pas de prix libre » du 01/09.
     */
    #[ORM\ManyToOne(targetEntity: Formule::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Formule $formula = null;

    #[ORM\Column(length: 12, enumType: MembershipPeriodicity::class)]
    private MembershipPeriodicity $periodicity = MembershipPeriodicity::Monthly;

    #[ORM\Column(length: 12, enumType: MembershipStatus::class, options: ['default' => 'active'])]
    private MembershipStatus $status = MembershipStatus::Active;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $subscribedOn;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $commitmentStartsOn;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $commitmentEndsOn;

    #[ORM\Column(type: 'smallint', options: ['default' => 30])]
    private int $noticePeriodDays = 30;

    /** En centimes entiers. Un flottant sur de l'argent produit des écarts bien réels sur un relevé. */
    #[ORM\Column(options: ['default' => 0])]
    private int $amountCents = 0;

    #[ORM\ManyToOne(targetEntity: MandatSepa::class)]
    #[ORM\JoinColumn(name: 'sepa_mandate_id', nullable: false)]
    private ?MandatSepa $sepaMandate = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMember(): ?Beneficiaire
    {
        return $this->member;
    }

    public function setMember(?Beneficiaire $member): self
    {
        $this->member = $member;

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

    public function getFormula(): ?Formule
    {
        return $this->formula;
    }

    public function setFormula(?Formule $formula): self
    {
        $this->formula = $formula;

        return $this;
    }

    public function getPeriodicity(): MembershipPeriodicity
    {
        return $this->periodicity;
    }

    public function setPeriodicity(MembershipPeriodicity $periodicity): self
    {
        $this->periodicity = $periodicity;

        return $this;
    }

    public function getStatus(): MembershipStatus
    {
        return $this->status;
    }

    public function setStatus(MembershipStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getSubscribedOn(): \DateTimeImmutable
    {
        return $this->subscribedOn;
    }

    public function setSubscribedOn(\DateTimeImmutable $subscribedOn): self
    {
        $this->subscribedOn = $subscribedOn;

        return $this;
    }

    public function getCommitmentStartsOn(): \DateTimeImmutable
    {
        return $this->commitmentStartsOn;
    }

    public function setCommitmentStartsOn(\DateTimeImmutable $commitmentStartsOn): self
    {
        $this->commitmentStartsOn = $commitmentStartsOn;

        return $this;
    }

    public function getCommitmentEndsOn(): \DateTimeImmutable
    {
        return $this->commitmentEndsOn;
    }

    public function setCommitmentEndsOn(\DateTimeImmutable $commitmentEndsOn): self
    {
        $this->commitmentEndsOn = $commitmentEndsOn;

        return $this;
    }

    public function getNoticePeriodDays(): int
    {
        return $this->noticePeriodDays;
    }

    public function setNoticePeriodDays(int $noticePeriodDays): self
    {
        $this->noticePeriodDays = $noticePeriodDays;

        return $this;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function setAmountCents(int $amountCents): self
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    public function getSepaMandate(): ?MandatSepa
    {
        return $this->sepaMandate;
    }

    public function setSepaMandate(?MandatSepa $sepaMandate): self
    {
        $this->sepaMandate = $sepaMandate;

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
}
