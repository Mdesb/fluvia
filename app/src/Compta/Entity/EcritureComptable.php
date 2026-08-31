<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Compta\Enum\StatutEcriture;
use App\Compta\State\ExtourneEcritureProcessor;
use App\Compta\State\GenererEcrituresProcessor;
use App\Compta\State\SaisirEcritureManuelleProcessor;
use App\Compta\State\ValiderEcritureProcessor;
use App\Compta\State\VerifierChaineEcritureProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Écriture comptable (RG-M6-04, RG-COMPTA-04) : append-only, chaînée NF525 (§6 du plan — champs
 * embarqués, pas de table `OperationScellee` dupliquée). Toute correction passe exclusivement par une
 * écriture d'extourne (`pieceExtourneDe`) — jamais de modification/suppression (garde
 * `EcritureInalterableListener`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_ecriture_comptable')]
#[ORM\UniqueConstraint(name: 'uniq_ecriture_profil_journal_sequence', columns: ['profil_exploitant_id', 'journal_id', 'numero_sequence'])]
#[ApiResource(
    shortName: 'EcritureComptable',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(
            uriTemplate: '/compta/ecritures/generer',
            read: false,
            input: false,
            security: "is_granted('PERM', 'compta.valider')",
            processor: GenererEcrituresProcessor::class,
            output: false,
        ),
        new Post(
            uriTemplate: '/compta/journal-entries/manual',
            read: false,
            input: false,
            security: "is_granted('PERM', 'compta.record_manual_entry')",
            processor: SaisirEcritureManuelleProcessor::class,
        ),
        new Post(
            uriTemplate: '/compta/ecritures/{id}/valider',
            read: true,
            input: false,
            security: "is_granted('PERM', 'compta.valider')",
            processor: ValiderEcritureProcessor::class,
        ),
        new Post(
            uriTemplate: '/compta/ecritures/{id}/extourne',
            read: true,
            input: false,
            security: "is_granted('PERM', 'compta.valider')",
            processor: ExtourneEcritureProcessor::class,
        ),
        new GetCollection(
            security: "is_granted('PERM', 'compta.lire')",
            uriTemplate: '/compta/ecritures/verifier-chaine',
            provider: VerifierChaineEcritureProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['ecriture:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['journal' => 'exact', 'periode' => 'exact', 'statut' => 'exact', 'venteOrigine' => 'exact'])]
class EcritureComptable
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ecriture:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ecriture:read'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\ManyToOne(targetEntity: Journal::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ecriture:read'])]
    private ?Journal $journal = null;

    #[ORM\ManyToOne(targetEntity: PeriodeComptable::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ecriture:read'])]
    private ?PeriodeComptable $periode = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['ecriture:read'])]
    private \DateTimeImmutable $dateEcriture;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['ecriture:read'])]
    private ?string $libelle = null;

    #[ORM\Column(length: 12, enumType: StatutEcriture::class, options: ['default' => 'provisoire'])]
    #[Groups(['ecriture:read'])]
    private StatutEcriture $statut = StatutEcriture::Provisoire;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['ecriture:read'])]
    private ?Uuid $venteOrigine = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['ecriture:read'])]
    private ?self $pieceExtourneDe = null;

    // --- Chaînage NF525 (prolongement §6 du plan) ---
    #[ORM\Column(type: 'bigint')]
    #[Groups(['ecriture:read', 'nf525:read'])]
    private int $numeroSequence = 0;

    #[ORM\Column(length: 128)]
    #[Groups(['ecriture:read', 'nf525:read'])]
    private string $empreinte = '';

    #[ORM\Column(length: 128, nullable: true)]
    #[Groups(['ecriture:read', 'nf525:read'])]
    private ?string $empreintePrecedente = null;

    #[ORM\Column(length: 512)]
    #[Groups(['ecriture:read', 'nf525:read'])]
    private string $signature = '';

    /**
     * L'INSTANTANE EXACT SUR LEQUEL L'EMPREINTE A ETE CALCULEE.
     *
     * Sans lui, la verification reconstruit le payload depuis les entites VIVANTES : un taux de TVA
     * corrige, un destinataire retype, et l'empreinte recalculee ne correspond plus — alors que rien
     * n'a ete altere. Meme patron que `App\Vente\Nf525\Entity\OperationScellee`, la seule des
     * trois chaines qui faisait bien.
     *
     * ⚠ `null` = scelle AVANT la conservation de l'instantane. Ce n'est pas un vide, c'est une date :
     * la verification ne peut alors que reconstruire, et elle doit le DIRE au lieu d'accuser.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['nf525:read'])]
    private ?array $payloadCanonique = null;

    /** @var Collection<int, LigneEcriture> */
    #[ORM\OneToMany(targetEntity: LigneEcriture::class, mappedBy: 'ecriture', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['ecriture:read'])]
    private Collection $lignes;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['ecriture:read'])]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateEcriture = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProfilExploitant(): ?ProfilExploitant
    {
        return $this->profilExploitant;
    }

    public function setProfilExploitant(?ProfilExploitant $profilExploitant): self
    {
        $this->profilExploitant = $profilExploitant;

        return $this;
    }

    public function getEtablissement(): ?\App\Organisation\Entity\Etablissement
    {
        return $this->profilExploitant?->getEtablissementPrincipal();
    }

    public function getJournal(): ?Journal
    {
        return $this->journal;
    }

    public function setJournal(?Journal $journal): self
    {
        $this->journal = $journal;

        return $this;
    }

    public function getPeriode(): ?PeriodeComptable
    {
        return $this->periode;
    }

    public function setPeriode(?PeriodeComptable $periode): self
    {
        $this->periode = $periode;

        return $this;
    }

    public function getDateEcriture(): \DateTimeImmutable
    {
        return $this->dateEcriture;
    }

    public function setDateEcriture(\DateTimeImmutable $dateEcriture): self
    {
        $this->dateEcriture = $dateEcriture;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getStatut(): StatutEcriture
    {
        return $this->statut;
    }

    public function setStatut(StatutEcriture $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getVenteOrigine(): ?Uuid
    {
        return $this->venteOrigine;
    }

    public function setVenteOrigine(?Uuid $venteOrigine): self
    {
        $this->venteOrigine = $venteOrigine;

        return $this;
    }

    public function getPieceExtourneDe(): ?self
    {
        return $this->pieceExtourneDe;
    }

    public function setPieceExtourneDe(?self $pieceExtourneDe): self
    {
        $this->pieceExtourneDe = $pieceExtourneDe;

        return $this;
    }

    public function getNumeroSequence(): int
    {
        return $this->numeroSequence;
    }

    public function setNumeroSequence(int $numeroSequence): self
    {
        $this->numeroSequence = $numeroSequence;

        return $this;
    }

    public function getEmpreinte(): string
    {
        return $this->empreinte;
    }

    public function setEmpreinte(string $empreinte): self
    {
        $this->empreinte = $empreinte;

        return $this;
    }

    public function getEmpreintePrecedente(): ?string
    {
        return $this->empreintePrecedente;
    }

    public function setEmpreintePrecedente(?string $empreintePrecedente): self
    {
        $this->empreintePrecedente = $empreintePrecedente;

        return $this;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function setSignature(string $signature): self
    {
        $this->signature = $signature;

        return $this;
    }

    /** @return Collection<int, LigneEcriture> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneEcriture $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setEcriture($this);
        }

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function totalDebitCentimes(): int
    {
        $total = 0;
        foreach ($this->lignes as $ligne) {
            $total += $ligne->getDebitCentimes();
        }

        return $total;
    }

    public function totalCreditCentimes(): int
    {
        $total = 0;
        foreach ($this->lignes as $ligne) {
            $total += $ligne->getCreditCentimes();
        }

        return $total;
    }

    public function estEquilibree(): bool
    {
        return $this->totalDebitCentimes() === $this->totalCreditCentimes();
    }

    /** Scellée = ne peut plus être modifiée (dès qu'elle a une empreinte, cf. `EcritureInalterableListener`). */
    public function estScellee(): bool
    {
        return $this->empreinte !== '';
    }

    /** @return array<string, mixed>|null null = scelle avant la conservation de l'instantane */
    public function getPayloadCanonique(): ?array
    {
        return $this->payloadCanonique;
    }

    /** @param array<string, mixed>|null $payloadCanonique */
    public function setPayloadCanonique(?array $payloadCanonique): self
    {
        $this->payloadCanonique = $payloadCanonique;

        return $this;
    }

}
