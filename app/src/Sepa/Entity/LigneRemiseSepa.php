<?php

declare(strict_types=1);

namespace App\Sepa\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Sepa\Enum\SeqTpSepa;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Ligne d'une remise SEPA (une transaction `DrctDbtTxInf` du pain.008, plan §2) : lien remise ↔
 * (mandat + montant + `EndToEndId` + libellé `RmtInf/Ustrd` + échéance d'origine). `referenceOrigine`
 * est l'identifiant opaque fourni par la verticale (`EcheanceSepaDue::$referenceOrigine`, plan §5) —
 * aucune dépendance à un type métier de la verticale (Sport, Piscine…).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sepa_ligne_remise')]
#[ApiResource(
    shortName: 'LigneRemiseSepa',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
    ],
    normalizationContext: ['groups' => ['ligne_remise_sepa:read']],
)]
class LigneRemiseSepa
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ligne_remise_sepa:read', 'rejet_sepa:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: RemiseSepa::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ligne_remise_sepa:read'])]
    private ?RemiseSepa $remise = null;

    #[ORM\ManyToOne(targetEntity: MandatSepa::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ligne_remise_sepa:read', 'rejet_sepa:read'])]
    private ?MandatSepa $mandat = null;

    #[ORM\Column(length: 4, enumType: SeqTpSepa::class)]
    #[Groups(['ligne_remise_sepa:read'])]
    private SeqTpSepa $seqTp = SeqTpSepa::Frst;

    #[ORM\Column]
    #[Groups(['ligne_remise_sepa:read'])]
    private int $montantCentimes = 0;

    #[ORM\Column(length: 35)]
    #[Groups(['ligne_remise_sepa:read', 'rejet_sepa:read'])]
    private string $endToEndId = '';

    #[ORM\Column(length: 140)]
    #[Groups(['ligne_remise_sepa:read'])]
    private string $libelle = '';

    /** Identifiant opaque de l'échéance d'origine côté verticale (plan §5). */
    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['ligne_remise_sepa:read'])]
    private ?string $referenceOrigine = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getMandat(): ?MandatSepa
    {
        return $this->mandat;
    }

    public function setMandat(?MandatSepa $mandat): self
    {
        $this->mandat = $mandat;

        return $this;
    }

    public function getSeqTp(): SeqTpSepa
    {
        return $this->seqTp;
    }

    public function setSeqTp(SeqTpSepa $seqTp): self
    {
        $this->seqTp = $seqTp;

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

    public function getEndToEndId(): string
    {
        return $this->endToEndId;
    }

    public function setEndToEndId(string $endToEndId): self
    {
        $this->endToEndId = $endToEndId;

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getReferenceOrigine(): ?string
    {
        return $this->referenceOrigine;
    }

    public function setReferenceOrigine(?string $referenceOrigine): self
    {
        $this->referenceOrigine = $referenceOrigine;

        return $this;
    }
}
