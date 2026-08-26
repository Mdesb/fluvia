<?php

declare(strict_types=1);

namespace App\Sepa\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Enum\SeqTpSepa;
use App\Sepa\Enum\StatutRemiseSepa;
use App\Sepa\State\GenererRemiseSepaProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Remise SEPA (lot pain.008.001.02, plan §2). Générique — remplace `App\Sport\Entity\RemiseSepa`.
 * `seqTp` porte la séquence **dominante** de la remise (informatif : `null` si la remise est mixte,
 * c'est-à-dire regroupe plusieurs `SeqTp` — chaque `LigneRemiseSepa` porte alors sa propre séquence
 * résolue, et `Pain008Generator` produit **un `PmtInf` par `SeqTp`** au sein du même message, §3/§8 du
 * plan). Le contenu XML généré est conservé (`contenuXml`) pour téléchargement (`GET
 * /sepa/remises/{id}/pain008`) sans avoir à le regénérer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sepa_remise')]
#[ORM\UniqueConstraint(name: 'uniq_sepa_remise_message_id', columns: ['message_id'])]
#[ApiResource(
    shortName: 'RemiseSepa',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
        new Post(
            uriTemplate: '/sepa/remises/generer',
            read: false,
            input: false,
            security: "is_granted('PERM', 'sepa.generer_remise') or is_granted('PERM', 'compta.gerer')",
            processor: GenererRemiseSepaProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['remise_sepa:read']],
)]
class RemiseSepa
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['remise_sepa:read', 'ligne_remise_sepa:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['remise_sepa:read'])]
    private ?Etablissement $etablissement = null;

    /** `GrpHdr/MsgId` (et base de `PmtInf/PmtInfId`). */
    #[ORM\Column(length: 35, nullable: true)]
    #[Groups(['remise_sepa:read'])]
    private ?string $messageId = null;

    /** `GrpHdr/CreDtTm`. */
    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['remise_sepa:read'])]
    private \DateTimeImmutable $dateCreation;

    /** `ReqdColltnDt` (commune à tous les `PmtInf` de la remise, simplification assumée §9). */
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['remise_sepa:read'])]
    private \DateTimeImmutable $dateCollecte;

    #[ORM\Column(length: 4, enumType: SeqTpSepa::class, nullable: true)]
    #[Groups(['remise_sepa:read'])]
    private ?SeqTpSepa $seqTp = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['remise_sepa:read'])]
    private int $nbTxs = 0;

    /** Somme des montants en centimes (converti en décimal 2 chiffres pour `CtrlSum` XML). */
    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['remise_sepa:read'])]
    private int $ctrlSumCentimes = 0;

    #[ORM\Column(length: 10, enumType: StatutRemiseSepa::class, options: ['default' => 'brouillon'])]
    #[Groups(['remise_sepa:read'])]
    private StatutRemiseSepa $statut = StatutRemiseSepa::Brouillon;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contenuXml = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['remise_sepa:read'])]
    private ?string $referenceTransmission = null;

    /** @var Collection<int, LigneRemiseSepa> */
    #[ORM\OneToMany(targetEntity: LigneRemiseSepa::class, mappedBy: 'remise', cascade: ['persist'])]
    private Collection $lignes;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
    }

    /**
     * Combien d'échéances dues ont été écartées faute de préavis, et pourquoi (PAY-2).
     *
     * **Porté par la remise et non journalisé ailleurs.** Une remise à zéro ligne parce que tout a été
     * exclu n'est pas une remise à zéro ligne parce qu'il n'y avait rien à collecter. Si les deux se
     * ressemblent, personne ne verra jamais le blocage — et c'est exactement ce qui s'est passé
     * jusqu'ici, où aucun préavis n'existait et où rien ne le disait.
     */
    #[ORM\Column(name: 'nb_exclues', options: ['default' => 0])]
    private int $nbExclues = 0;

    #[ORM\Column(name: 'motif_exclusion', length: 255, nullable: true)]
    private ?string $motifExclusion = null;

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): self
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getDateCollecte(): \DateTimeImmutable
    {
        return $this->dateCollecte;
    }

    public function setDateCollecte(\DateTimeImmutable $dateCollecte): self
    {
        $this->dateCollecte = $dateCollecte;

        return $this;
    }

    public function getSeqTp(): ?SeqTpSepa
    {
        return $this->seqTp;
    }

    public function setSeqTp(?SeqTpSepa $seqTp): self
    {
        $this->seqTp = $seqTp;

        return $this;
    }

    public function getNbTxs(): int
    {
        return $this->nbTxs;
    }

    public function setNbTxs(int $nbTxs): self
    {
        $this->nbTxs = $nbTxs;

        return $this;
    }

    public function getCtrlSumCentimes(): int
    {
        return $this->ctrlSumCentimes;
    }

    public function setCtrlSumCentimes(int $ctrlSumCentimes): self
    {
        $this->ctrlSumCentimes = $ctrlSumCentimes;

        return $this;
    }

    public function getStatut(): StatutRemiseSepa
    {
        return $this->statut;
    }

    public function setStatut(StatutRemiseSepa $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getContenuXml(): ?string
    {
        return $this->contenuXml;
    }

    public function setContenuXml(?string $contenuXml): self
    {
        $this->contenuXml = $contenuXml;

        return $this;
    }

    public function getReferenceTransmission(): ?string
    {
        return $this->referenceTransmission;
    }

    public function setReferenceTransmission(?string $referenceTransmission): self
    {
        $this->referenceTransmission = $referenceTransmission;

        return $this;
    }

    /** @return Collection<int, LigneRemiseSepa> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneRemiseSepa $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setRemise($this);
        }

        return $this;
    }

    public function getNbExclues(): int
    {
        return $this->nbExclues;
    }

    public function setNbExclues(int $nbExclues): self
    {
        $this->nbExclues = $nbExclues;

        return $this;
    }

    public function getMotifExclusion(): ?string
    {
        return $this->motifExclusion;
    }

    public function setMotifExclusion(?string $motifExclusion): self
    {
        $this->motifExclusion = $motifExclusion;

        return $this;
    }
}
